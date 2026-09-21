<?php

namespace Tests\Feature\Tickets;

use App\Enums\RoleName;
use App\Enums\TicketStatus;
use App\Models\AuditLog;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\TicketMessage;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class TicketMessageTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'INTERNAL-ONLY-SECRET';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
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

    private function note(Ticket $ticket, string $body = self::SECRET)
    {
        return $this->postJson("/api/tickets/{$ticket->id}/internal-notes", ['body' => $body]);
    }

    private function reply(Ticket $ticket, string $body = 'Hello there')
    {
        return $this->postJson("/api/tickets/{$ticket->id}/replies", ['body' => $body]);
    }

    // ---- Public replies ------------------------------------------------------

    public function test_customer_can_reply_to_their_own_ticket(): void
    {
        [$ticket, $customer] = $this->scene();

        $this->as($customer)->reply($ticket, '  Please help  ')
            ->assertCreated()
            ->assertJsonPath('entry.type', 'public_reply')
            ->assertJsonPath('entry.body', 'Please help')
            ->assertJsonPath('entry.author.id', $customer->id)
            ->assertJsonPath('entry.author.role', 'customer')
            ->assertJsonPath('abilities.can_reply', true);

        $this->assertDatabaseHas('ticket_messages', ['ticket_id' => $ticket->id, 'user_id' => $customer->id, 'type' => 'public_reply', 'body' => 'Please help']);
    }

    public function test_assigned_agent_and_admin_can_reply(): void
    {
        [$ticket, , $agent] = $this->scene();

        $this->as($agent)->reply($ticket)->assertCreated();
        $this->as($this->makeUser(RoleName::Admin))->reply($ticket)->assertCreated();
        $this->assertSame(2, $ticket->messages()->count());
    }

    public function test_unrelated_users_cannot_reply(): void
    {
        [$ticket] = $this->scene();

        $this->as($this->makeUser(RoleName::Customer))->reply($ticket)->assertForbidden();
        $this->as($this->makeUser(RoleName::Agent))->reply($ticket)->assertForbidden(); // not assigned
        $this->assertSame(0, $ticket->messages()->count());
    }

    public function test_guest_cannot_reply(): void
    {
        [$ticket] = $this->scene();

        $this->postJson("/api/tickets/{$ticket->id}/replies", ['body' => 'x'])->assertUnauthorized();
    }

    public function test_user_without_the_reply_permission_cannot_reply(): void
    {
        [$ticket, $customer] = $this->scene();
        $customer->roles->first()->revokePermissionTo('ticket.reply');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->as($customer->fresh())->reply($ticket)->assertForbidden();
        $this->assertFalse($this->getJson("/api/tickets/{$ticket->id}")->json('abilities.can_reply'));
    }

    public function test_body_is_validated(): void
    {
        [$ticket, $customer] = $this->scene();
        $this->as($customer);

        $this->postJson("/api/tickets/{$ticket->id}/replies", [])->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->reply($ticket, '')->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->reply($ticket, "   \n\t  ")->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->postJson("/api/tickets/{$ticket->id}/replies", ['body' => ['x']])->assertUnprocessable();
        $this->reply($ticket, str_repeat('a', 5001))->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->reply($ticket, str_repeat('a', 5000))->assertCreated();
        $this->assertSame(1, $ticket->messages()->count());
    }

    public function test_html_is_stored_and_returned_as_plain_text(): void
    {
        [$ticket, $customer] = $this->scene();

        $this->as($customer)->reply($ticket, '<script>alert(1)</script>')
            ->assertCreated()
            ->assertJsonPath('entry.body', '<script>alert(1)</script>');
    }

    public function test_conversation_is_ordered_oldest_first_and_pages_backwards(): void
    {
        [$ticket, $customer] = $this->scene();
        foreach (range(1, 5) as $i) {
            TicketMessage::create(['ticket_id' => $ticket->id, 'user_id' => $customer->id, 'type' => 'public_reply', 'body' => "m{$i}"]);
        }

        $page = $this->as($customer)->getJson("/api/tickets/{$ticket->id}/messages?per_page=2")->assertOk();
        $this->assertSame(['m4', 'm5'], array_column($page->json('data'), 'body'));
        $this->assertTrue($page->json('has_more'));

        $before = $page->json('data.0.id');
        $older = $this->getJson("/api/tickets/{$ticket->id}/messages?per_page=10&before={$before}")->assertOk();
        $this->assertSame(['m1', 'm2', 'm3'], array_column($older->json('data'), 'body'));
        $this->assertFalse($older->json('has_more'));
    }

    // ---- Resolved -> Open ----------------------------------------------------

    public function test_customer_reply_on_a_resolved_ticket_reopens_it_with_history(): void
    {
        [$ticket, $customer] = $this->scene(['status' => TicketStatus::Resolved, 'resolved_at' => now()->subDay()]);

        $this->as($customer)->reply($ticket)
            ->assertCreated()
            ->assertJsonPath('ticket.status', 'open')
            ->assertJsonPath('ticket.resolved_at', null)
            ->assertJsonPath('events.0.type', 'status_changed')
            ->assertJsonPath('events.0.from', 'resolved')
            ->assertJsonPath('events.0.to', 'open')
            ->assertJsonPath('events.0.reason', 'customer_reply')
            ->assertJsonPath('events.0.actor.id', $customer->id);

        $fresh = $ticket->fresh();
        $this->assertSame(TicketStatus::Open, $fresh->status);
        $this->assertNull($fresh->resolved_at);
        $this->assertNull($fresh->closed_at);
        $this->assertSame($ticket->assigned_to, $fresh->assigned_to);
        $this->assertSame(1, TicketEvent::where('ticket_id', $ticket->id)->count());
    }

    public function test_a_rejected_reply_does_not_reopen_the_ticket(): void
    {
        [$ticket, $customer] = $this->scene(['status' => TicketStatus::Resolved, 'resolved_at' => now()]);

        $this->as($customer)->reply($ticket, '   ')->assertUnprocessable();

        $this->assertSame(TicketStatus::Resolved, $ticket->fresh()->status);
        $this->assertNotNull($ticket->fresh()->resolved_at);
        $this->assertSame(0, TicketEvent::count());
        $this->assertSame(0, $ticket->messages()->count());
    }

    public function test_staff_reply_and_internal_note_do_not_change_a_resolved_ticket(): void
    {
        [$ticket, , $agent] = $this->scene(['status' => TicketStatus::Resolved, 'resolved_at' => now()]);

        $this->as($agent)->reply($ticket)->assertCreated()->assertJsonPath('ticket.status', 'resolved');
        $this->note($ticket)->assertCreated()->assertJsonPath('ticket.status', 'resolved');
        $this->assertSame(0, TicketEvent::count());
    }

    public function test_customer_reply_on_open_and_in_progress_tickets_keeps_the_status(): void
    {
        foreach ([TicketStatus::Open, TicketStatus::InProgress] as $status) {
            [$ticket, $customer] = $this->scene(['status' => $status]);
            $this->as($customer)->reply($ticket)->assertCreated()->assertJsonPath('ticket.status', $status->value);
        }
        $this->assertSame(0, TicketEvent::count());
    }

    // ---- Closed tickets ------------------------------------------------------

    public function test_closed_tickets_take_no_replies_or_notes(): void
    {
        [$ticket, $customer, $agent] = $this->scene(['status' => TicketStatus::Closed, 'closed_at' => now()]);

        $this->as($customer)->reply($ticket)->assertUnprocessable()->assertJsonPath('code', 'ticket_closed');
        $this->as($agent)->reply($ticket)->assertUnprocessable()->assertJsonPath('code', 'ticket_closed');
        $this->note($ticket)->assertUnprocessable()->assertJsonPath('code', 'ticket_closed');

        $this->assertSame(0, $ticket->messages()->count());
        $this->assertSame(TicketStatus::Closed, $ticket->fresh()->status);
    }

    public function test_reopening_a_closed_ticket_allows_messages_again(): void
    {
        [$ticket, , $agent] = $this->scene(['status' => TicketStatus::Closed, 'closed_at' => now()]);

        $this->as($agent)->postJson("/api/tickets/{$ticket->id}/status", ['status' => 'in_progress'])->assertOk();
        $this->reply($ticket)->assertCreated();
    }

    // ---- Internal notes ------------------------------------------------------

    public function test_staff_can_write_and_read_internal_notes(): void
    {
        [$ticket, , $agent] = $this->scene();

        $this->as($agent)->note($ticket)
            ->assertCreated()
            ->assertJsonPath('entry.type', 'internal_note')
            ->assertJsonPath('abilities.can_view_notes', true);

        $this->assertContains(self::SECRET, array_column($this->getJson("/api/tickets/{$ticket->id}/messages")->json('data'), 'body'));

        $admin = $this->makeUser(RoleName::Admin);
        $this->as($admin)->note($ticket, 'Admin note')->assertCreated();
        $this->assertCount(2, $this->getJson("/api/tickets/{$ticket->id}/messages")->json('data'));
    }

    public function test_customers_cannot_write_internal_notes(): void
    {
        [$ticket, $customer] = $this->scene();

        $this->as($customer)->note($ticket)->assertForbidden();
        $this->assertSame(0, $ticket->messages()->count());
    }

    public function test_customers_never_receive_internal_notes_from_any_endpoint(): void
    {
        [$ticket, $customer, $agent] = $this->scene();
        $this->as($agent)->note($ticket)->assertCreated();
        $this->reply($ticket, 'Public answer')->assertCreated();

        $this->as($customer);
        $messages = $this->getJson("/api/tickets/{$ticket->id}/messages")->assertOk();
        $messages->assertJsonCount(1, 'data')->assertJsonPath('data.0.body', 'Public answer');
        $this->assertStringNotContainsString(self::SECRET, $messages->getContent());

        // Every other ticket response, including one produced by the customer's own reply.
        $responses = [
            $this->getJson("/api/tickets/{$ticket->id}"),
            $this->getJson('/api/tickets'),
            $this->reply($ticket, 'Thanks'),
            $this->getJson("/api/tickets/{$ticket->id}/messages?before=999999&per_page=100"),
        ];
        foreach ($responses as $response) {
            $this->assertStringNotContainsString(self::SECRET, $response->getContent());
        }

        $abilities = $this->getJson("/api/tickets/{$ticket->id}")->json('abilities');
        $this->assertFalse($abilities['can_view_notes']);
        $this->assertFalse($abilities['can_add_note']);
    }

    public function test_visibility_follows_the_permission_not_the_role_name(): void
    {
        [$ticket, $customer, $agent] = $this->scene();
        $this->as($agent)->note($ticket)->assertCreated();

        // Removing the permission from a role takes effect at once.
        $agent->roles->first()->revokePermissionTo('ticket.internal_note');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $agent = $agent->fresh();
        $body = $this->as($agent)->getJson("/api/tickets/{$ticket->id}/messages")->assertOk()->getContent();
        $this->assertStringNotContainsString(self::SECRET, $body);
        $this->note($ticket)->assertForbidden();
        $this->assertFalse($this->getJson("/api/tickets/{$ticket->id}")->json('abilities.can_view_notes'));
    }

    public function test_a_customer_granted_the_note_permission_still_only_reaches_their_own_tickets(): void
    {
        [$ticket, , $agent] = $this->scene();
        $this->as($agent)->note($ticket)->assertCreated();

        $stranger = $this->makeUser(RoleName::Customer);
        $stranger->givePermissionTo('ticket.internal_note');

        $this->as($stranger)->getJson("/api/tickets/{$ticket->id}/messages")->assertForbidden();
        $this->note($ticket)->assertForbidden();
    }

    // ---- Access to the endpoints --------------------------------------------

    public function test_messages_of_someone_elses_ticket_are_forbidden(): void
    {
        [$ticket] = $this->scene();

        $this->as($this->makeUser(RoleName::Customer))->getJson("/api/tickets/{$ticket->id}/messages")->assertForbidden();
        $this->as($this->makeUser(RoleName::Agent))->getJson("/api/tickets/{$ticket->id}/messages")->assertForbidden();
        $this->getJson('/api/tickets/999999/messages')->assertNotFound();
    }

    public function test_user_ids_in_the_request_cannot_be_forged(): void
    {
        [$ticket, $customer] = $this->scene();
        $other = $this->makeUser(RoleName::Admin);

        $this->as($customer)->postJson("/api/tickets/{$ticket->id}/replies", ['body' => 'hi', 'user_id' => $other->id, 'type' => 'internal_note', 'ticket_id' => 999])
            ->assertCreated();

        $this->assertDatabaseHas('ticket_messages', ['ticket_id' => $ticket->id, 'user_id' => $customer->id, 'type' => 'public_reply']);
    }

    public function test_audit_log_records_the_event_but_not_the_text(): void
    {
        [$ticket, , $agent] = $this->scene();

        $this->as($agent)->note($ticket)->assertCreated();

        $log = AuditLog::where('action', 'ticket.internal_note')->firstOrFail();
        $this->assertStringNotContainsString(self::SECRET, json_encode($log->toArray()));
    }

    public function test_messages_survive_deleting_the_author_and_go_with_the_ticket(): void
    {
        [$ticket, , $agent] = $this->scene();
        $this->as($agent)->reply($ticket)->assertCreated();

        $ticket->update(['assigned_to' => null]);
        $agent->delete();
        $this->assertNull(TicketMessage::first()->user_id);

        $ticket->delete();
        $this->assertSame(0, TicketMessage::count());
    }
}
