<?php

namespace Tests\Feature\Tickets;

use App\Enums\RoleName;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketMessage;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TicketAttachmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('attachments');
        RateLimiter::clear('ticket-upload');
    }

    private function makeUser(RoleName $role): User
    {
        return User::factory()->create()->assignRole($role->value);
    }

    private function as(User $user): static
    {
        Sanctum::actingAs($user);

        return $this;
    }

    /** @return array{0: Ticket, 1: User, 2: User} ticket, customer, assigned agent */
    private function scene(array $attributes = []): array
    {
        $customer = $this->makeUser(RoleName::Customer);
        $agent = $this->makeUser(RoleName::Agent);
        $ticket = Ticket::factory()->create(array_merge(['customer_id' => $customer->id, 'assigned_to' => $agent->id], $attributes));

        return [$ticket, $customer, $agent];
    }

    private function png(string $name = 'shot.png'): UploadedFile
    {
        return UploadedFile::fake()->image($name, 8, 8);
    }

    private function text(string $content = 'hello', string $name = 'notes.txt'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }

    private function reply(Ticket $ticket, array $files, string $body = 'See attached')
    {
        return $this->post("/api/tickets/{$ticket->id}/replies", ['body' => $body, 'attachments' => $files], ['Accept' => 'application/json']);
    }

    private function note(Ticket $ticket, array $files, string $body = 'Internal')
    {
        return $this->post("/api/tickets/{$ticket->id}/internal-notes", ['body' => $body, 'attachments' => $files], ['Accept' => 'application/json']);
    }

    private function download(Ticket $ticket, int $attachmentId)
    {
        return $this->get("/api/tickets/{$ticket->id}/attachments/{$attachmentId}", ['Accept' => 'application/json']);
    }

    private function storedFiles(): array
    {
        return Storage::disk('attachments')->allFiles();
    }

    // ---- Uploading -----------------------------------------------------------

    public function test_a_reply_can_carry_files_stored_privately_with_server_made_names(): void
    {
        [$ticket, $customer] = $this->scene();

        $response = $this->as($customer)->reply($ticket, [$this->png('My Screenshot.png'), $this->text('log line', 'a.txt')])
            ->assertCreated()
            ->assertJsonCount(2, 'entry.attachments')
            ->assertJsonPath('entry.attachments.0.name', 'My Screenshot.png')
            ->assertJsonPath('entry.attachments.0.mime_type', 'image/png')
            ->assertJsonPath('entry.attachments.0.is_image', true)
            ->assertJsonPath('entry.attachments.1.mime_type', 'text/plain');

        // The API never reveals where or how a file is stored.
        $this->assertStringNotContainsString('storage_path', $response->getContent());
        $this->assertStringNotContainsString('sha256', $response->getContent());
        $this->assertStringNotContainsString('tickets/', $response->getContent());

        $rows = TicketAttachment::orderBy('id')->get();
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertMatchesRegularExpression("#^tickets/{$ticket->id}/[0-9a-z]{26}$#", $row->storage_path);
            Storage::disk('attachments')->assertExists($row->storage_path);
            $this->assertSame($customer->id, $row->uploaded_by);
            $this->assertSame(TicketMessage::first()->id, $row->ticket_message_id);
            $this->assertSame(64, strlen($row->sha256));
        }
        $this->assertCount(2, $this->storedFiles());
    }

    public function test_files_can_be_attached_when_opening_a_ticket(): void
    {
        $customer = $this->makeUser(RoleName::Customer);

        $this->as($customer)->post('/api/tickets', [
            'subject' => 'Broken', 'description' => 'See picture', 'attachments' => [$this->png()],
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonCount(1, 'ticket.attachments')
            ->assertJsonPath('ticket.attachments.0.name', 'shot.png');

        $row = TicketAttachment::firstOrFail();
        $this->assertNull($row->ticket_message_id);
        $this->assertSame(Ticket::firstOrFail()->id, $row->ticket_id);

        // The opening files are visible to the assigned agent, but the list omits them.
        $ticket = Ticket::firstOrFail();
        $ticket->forceFill(["assigned_to" => ($agent = $this->makeUser(RoleName::Agent))->id])->save();
        $this->as($agent)->getJson("/api/tickets/{$ticket->id}")->assertOk()->assertJsonCount(1, "ticket.attachments");
        $this->assertStringNotContainsString('attachments', $this->getJson('/api/tickets')->getContent());
    }

    public function test_a_ticket_without_files_still_works_and_lists_none(): void
    {
        $customer = $this->makeUser(RoleName::Customer);

        $this->as($customer)->postJson('/api/tickets', ['subject' => 'S', 'description' => 'D'])
            ->assertCreated()->assertJsonCount(0, 'ticket.attachments');
        $this->assertSame([], $this->storedFiles());
    }

    public function test_one_bad_file_rejects_the_whole_message_and_stores_nothing(): void
    {
        [$ticket, $customer] = $this->scene();

        $this->as($customer)->reply($ticket, [$this->png(), $this->text("<?php system(\$_GET['c']);", 'shell.png')])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('attachments.1');

        $this->assertSame(0, TicketMessage::count());
        $this->assertSame(0, TicketAttachment::count());
        $this->assertSame([], $this->storedFiles());
        $this->assertSame(TicketStatus::Open, $ticket->fresh()->status);
    }

    public function test_dangerous_types_are_refused(): void
    {
        [$ticket, $customer] = $this->scene();
        $this->as($customer);

        foreach (['run.php', 'setup.exe', 'image.svg', 'page.html', 'macro.docm', 'pack.zip', 'x.js'] as $name) {
            $this->reply($ticket, [$this->text('harmless', $name)])->assertUnprocessable()->assertJsonValidationErrors('attachments.0');
        }
        $this->assertSame([], $this->storedFiles());
    }

    public function test_count_and_size_limits_apply(): void
    {
        [$ticket, $customer] = $this->scene();
        $this->as($customer);

        $six = array_map(fn ($i) => $this->text("f{$i}", "f{$i}.txt"), range(1, 6));
        $this->reply($ticket, $six)->assertUnprocessable()->assertJsonValidationErrors('attachments');

        config(['helpdesk.attachments.max_size_kb' => 1]);
        $this->reply($ticket, [$this->text(str_repeat('a', 2000))])->assertUnprocessable()->assertJsonValidationErrors('attachments.0');

        config(['helpdesk.attachments.max_size_kb' => 5120, 'helpdesk.attachments.max_request_kb' => 1]);
        $this->reply($ticket, [$this->text(str_repeat('a', 700), 'a.txt'), $this->text(str_repeat('b', 700), 'b.txt')])
            ->assertUnprocessable()->assertJsonValidationErrors('attachments');

        $this->assertSame([], $this->storedFiles());
        $this->assertSame(0, TicketMessage::count());
    }

    public function test_a_ticket_has_a_total_attachment_cap(): void
    {
        [$ticket, $customer] = $this->scene();
        config(['helpdesk.attachments.max_ticket_files' => 2]);
        $this->as($customer);

        $this->reply($ticket, [$this->text('1', '1.txt'), $this->text('2', '2.txt')])->assertCreated();
        $this->reply($ticket, [$this->text('3', '3.txt')])->assertUnprocessable()->assertJsonPath('code', 'attachment_limit');

        $this->assertSame(2, TicketAttachment::count());
        $this->assertSame(1, TicketMessage::count()); // the refused message was not kept
        $this->assertCount(2, $this->storedFiles());
    }

    public function test_closed_tickets_take_no_files(): void
    {
        [$ticket, , $agent] = $this->scene(['status' => TicketStatus::Closed, 'closed_at' => now()]);

        $this->as($agent)->reply($ticket, [$this->png()])->assertUnprocessable()->assertJsonPath('code', 'ticket_closed');
        $this->note($ticket, [$this->png()])->assertUnprocessable()->assertJsonPath('code', 'ticket_closed');
        $this->assertSame([], $this->storedFiles());
    }

    public function test_a_customer_reply_with_files_still_reopens_a_resolved_ticket(): void
    {
        [$ticket, $customer] = $this->scene(['status' => TicketStatus::Resolved, 'resolved_at' => now()]);

        $this->as($customer)->reply($ticket, [$this->png()])->assertCreated()->assertJsonPath('ticket.status', 'open');
        $this->assertSame(1, TicketAttachment::count());
    }

    public function test_a_failure_while_saving_removes_the_files_already_written_and_the_message(): void
    {
        [$ticket, $customer] = $this->scene();

        $calls = 0;
        TicketAttachment::creating(function () use (&$calls) {
            if (++$calls === 2) {
                throw new \RuntimeException('database went away');
            }
        });

        $this->as($customer)->reply($ticket, [$this->png(), $this->text()])->assertStatus(500);

        $this->assertSame(0, TicketMessage::count());
        $this->assertSame(0, TicketAttachment::count());
        $this->assertSame([], $this->storedFiles()); // the first file was written, then cleaned up
    }

    public function test_a_failure_opening_a_ticket_leaves_no_ticket_and_no_files(): void
    {
        $customer = $this->makeUser(RoleName::Customer);
        TicketAttachment::creating(fn () => throw new \RuntimeException('boom'));

        $this->as($customer)->post('/api/tickets', ['subject' => 'S', 'description' => 'D', 'attachments' => [$this->png()]], ['Accept' => 'application/json'])
            ->assertStatus(500);

        $this->assertSame(0, Ticket::count());
        $this->assertSame([], $this->storedFiles());
    }

    public function test_only_people_who_may_reply_can_upload(): void
    {
        [$ticket] = $this->scene();

        $this->as($this->makeUser(RoleName::Customer))->reply($ticket, [$this->png()])->assertForbidden();
        $this->as($this->makeUser(RoleName::Agent))->reply($ticket, [$this->png()])->assertForbidden();
        $this->as($this->makeUser(RoleName::Customer))->note($ticket, [$this->png()])->assertForbidden();
        $this->assertSame([], $this->storedFiles());
    }

    public function test_uploads_are_rate_limited_but_plain_replies_are_not(): void
    {
        [$ticket, $customer] = $this->scene();
        config(['helpdesk.attachments.max_ticket_files' => 1000]);
        $this->as($customer);

        for ($i = 0; $i < 30; $i++) {
            $this->reply($ticket, [$this->text("n{$i}", "n{$i}.txt")])->assertCreated();
        }
        $this->reply($ticket, [$this->text('one too many')])->assertStatus(429);
        $this->postJson("/api/tickets/{$ticket->id}/replies", ['body' => 'no files'])->assertCreated();
    }

    public function test_an_oversized_request_gets_a_clear_json_error(): void
    {
        [$ticket, $customer] = $this->scene();
        Sanctum::actingAs($customer);

        $this->call('POST', "/api/tickets/{$ticket->id}/replies", [], [], [], [
            'CONTENT_LENGTH' => 500 * 1024 * 1024,
            'HTTP_ACCEPT' => 'application/json',
        ])->assertStatus(413)->assertJsonPath('code', 'payload_too_large');
    }

    // ---- Downloading ---------------------------------------------------------

    public function test_download_sends_the_file_as_an_attachment_with_safe_headers(): void
    {
        [$ticket, $customer] = $this->scene();
        $this->as($customer)->reply($ticket, [$this->text("secret\ncontent", 'Quarterly report.txt')])->assertCreated();
        $id = TicketAttachment::firstOrFail()->id;

        $response = $this->download($ticket, $id)->assertOk();

        $this->assertSame("secret\ncontent", $response->streamedContent());
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('Quarterly report.txt', $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('text/plain', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('sandbox', $response->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_a_lying_client_type_and_hostile_name_cannot_change_how_a_file_is_served(): void
    {
        [$ticket, $customer] = $this->scene();
        $file = UploadedFile::fake()->createWithContent("evil\u{202E}fdp.txt", 'plain');
        $this->as($customer)->reply($ticket, [$file])->assertCreated();

        $row = TicketAttachment::firstOrFail();
        $this->assertStringNotContainsString("\u{202E}", $row->original_name);
        $response = $this->download($ticket, $row->id)->assertOk();
        $this->assertStringStartsWith('text/plain', $response->headers->get('Content-Type'));
        $this->assertStringNotContainsString("\u{202E}", $response->headers->get('Content-Disposition'));
    }

    public function test_download_requires_authentication_and_a_visible_ticket(): void
    {
        [$ticket, $customer] = $this->scene();
        $this->as($customer)->reply($ticket, [$this->png()])->assertCreated();
        $id = TicketAttachment::firstOrFail()->id;

        $this->app['auth']->forgetGuards();
        $this->download($ticket, $id)->assertUnauthorized();

        $this->as($this->makeUser(RoleName::Customer))->download($ticket, $id)->assertForbidden();
        $this->as($this->makeUser(RoleName::Agent))->download($ticket, $id)->assertForbidden(); // not assigned
    }

    public function test_a_file_cannot_be_fetched_through_another_tickets_url(): void
    {
        [$ticket, $customer] = $this->scene();
        $other = Ticket::factory()->create(['customer_id' => $customer->id]);
        $this->as($customer)->reply($ticket, [$this->png()])->assertCreated();
        $id = TicketAttachment::firstOrFail()->id;

        $this->download($other, $id)->assertNotFound();
        $this->download($ticket, 999999)->assertNotFound();
        $this->get("/api/tickets/{$ticket->id}/attachments/abc", ['Accept' => 'application/json'])->assertNotFound();
    }

    public function test_a_missing_file_is_a_404_not_a_crash(): void
    {
        [$ticket, $customer] = $this->scene();
        $this->as($customer)->reply($ticket, [$this->png()])->assertCreated();
        $row = TicketAttachment::firstOrFail();
        Storage::disk('attachments')->delete($row->storage_path);

        $this->download($ticket, $row->id)->assertNotFound();
    }

    // ---- Internal notes' files ----------------------------------------------

    public function test_files_on_internal_notes_are_never_visible_to_the_customer(): void
    {
        [$ticket, $customer, $agent] = $this->scene();
        $this->as($agent)->note($ticket, [$this->text('TOP-SECRET-FILE', 'internal.txt')])->assertCreated()
            ->assertJsonCount(1, 'entry.attachments');
        $noteFile = TicketAttachment::firstOrFail();
        $this->reply($ticket, [$this->text('public file', 'public.txt')])->assertCreated();
        $publicFile = TicketAttachment::orderByDesc('id')->firstOrFail();

        // Staff can read both.
        $this->download($ticket, $noteFile->id)->assertOk();
        $messages = $this->getJson("/api/tickets/{$ticket->id}/messages")->assertOk();
        $this->assertStringContainsString('internal.txt', $messages->getContent());

        // The customer cannot: not in any listing, and the id answers 404 (not 403), so it cannot be probed.
        $this->as($customer);
        $visible = $this->getJson("/api/tickets/{$ticket->id}/messages")->assertOk()->getContent();
        $this->assertStringNotContainsString('internal.txt', $visible);
        $this->assertStringContainsString('public.txt', $visible);
        $this->assertStringNotContainsString('internal.txt', $this->getJson("/api/tickets/{$ticket->id}")->getContent());
        $this->download($ticket, $noteFile->id)->assertNotFound();
        $this->download($ticket, $publicFile->id)->assertOk();
    }

    public function test_note_file_visibility_follows_the_permission_not_the_role_name(): void
    {
        [$ticket, , $agent] = $this->scene();
        $this->as($agent)->note($ticket, [$this->png()])->assertCreated();
        $id = TicketAttachment::firstOrFail()->id;

        $agent->roles->first()->revokePermissionTo('ticket.internal_note');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->as($agent->fresh())->download($ticket, $id)->assertNotFound();
    }

    public function test_admin_and_super_admin_can_download_any_ticket_file(): void
    {
        [$ticket, , $agent] = $this->scene();
        $this->as($agent)->note($ticket, [$this->png()])->assertCreated();
        $id = TicketAttachment::firstOrFail()->id;

        $this->as($this->makeUser(RoleName::Admin))->download($ticket, $id)->assertOk();
        $this->as($this->makeUser(RoleName::SuperAdmin))->download($ticket, $id)->assertOk();
    }

    // ---- Cleanup -------------------------------------------------------------

    public function test_deleting_a_ticket_removes_its_files(): void
    {
        [$ticket, $customer] = $this->scene();
        $this->as($customer)->reply($ticket, [$this->png(), $this->text()])->assertCreated();
        $this->assertCount(2, $this->storedFiles());

        $ticket->forceFill(['assigned_to' => null])->save();
        $ticket->delete();

        $this->assertSame([], $this->storedFiles());
        $this->assertSame(0, TicketAttachment::count());
    }

    public function test_the_orphan_command_removes_only_old_unreferenced_files(): void
    {
        [$ticket, $customer] = $this->scene();
        $this->as($customer)->reply($ticket, [$this->png()])->assertCreated();
        $known = TicketAttachment::firstOrFail()->storage_path;

        $disk = Storage::disk('attachments');
        $disk->put("tickets/{$ticket->id}/oldorphan", 'x');
        $disk->put("tickets/{$ticket->id}/neworphan", 'x');
        touch($disk->path("tickets/{$ticket->id}/oldorphan"), time() - 3 * 86400);
        touch($disk->path($known), time() - 3 * 86400); // an old file that IS referenced

        $this->artisan('helpdesk:prune-orphan-attachments', ['--dry-run' => true])->assertSuccessful();
        $disk->assertExists("tickets/{$ticket->id}/oldorphan");

        $this->artisan('helpdesk:prune-orphan-attachments')->assertSuccessful();
        $disk->assertMissing("tickets/{$ticket->id}/oldorphan");
        $disk->assertExists("tickets/{$ticket->id}/neworphan"); // could belong to an upload in progress
        $disk->assertExists($known);
    }

    public function test_the_orphan_command_reports_rows_whose_file_is_gone(): void
    {
        [$ticket, $customer] = $this->scene();
        $this->as($customer)->reply($ticket, [$this->png()])->assertCreated();
        Storage::disk('attachments')->delete(TicketAttachment::firstOrFail()->storage_path);

        $this->artisan('helpdesk:prune-orphan-attachments')
            ->expectsOutputToContain('Database row without a file')
            ->assertSuccessful();
        $this->assertSame(1, TicketAttachment::count()); // never deleted automatically
    }

    public function test_the_audit_log_counts_files_but_never_names_them(): void
    {
        [$ticket, $customer] = $this->scene();
        $this->as($customer)->reply($ticket, [$this->text('x', 'private-name.txt')])->assertCreated();

        $log = \App\Models\AuditLog::where('action', 'ticket.public_reply')->firstOrFail();
        $this->assertStringNotContainsString('private-name', json_encode($log->toArray()));
        $this->assertSame(1, $log->metadata['attachments'] ?? $log->data['attachments'] ?? null);
    }
}
