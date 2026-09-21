<?php

namespace Tests\Feature\Notifications;

use App\Enums\RoleName;
use App\Enums\TicketActivity;
use App\Enums\TicketStatus;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TicketActivityNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The endpoints against the notification rules: who is emailed for each real request, that
 * nobody gets two emails from one request, that nothing goes out when nothing changed or the
 * request failed, and that a rollback sends nothing.
 */
class TicketNotificationFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    private User $agent;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Notification::fake();
        $this->customer = $this->user(RoleName::Customer);
        $this->agent = $this->user(RoleName::Agent);
        $this->admin = $this->user(RoleName::Admin);
    }

    protected function tearDown(): void
    {
        AuditLog::flushEventListeners();
        parent::tearDown();
    }

    private function user(RoleName $role): User
    {
        return User::factory()->create()->assignRole($role->value);
    }

    private function as(User $user): static
    {
        Sanctum::actingAs($user);

        return $this;
    }

    private function ticket(array $attributes = []): Ticket
    {
        return Ticket::factory()->create(array_merge([
            'customer_id' => $this->customer->id,
            'assigned_to' => $this->agent->id,
        ], $attributes));
    }

    /**
     * What was emailed, as ['userId' => ['activity', ...]], for everyone in the scene.
     * Also fails the test if anyone received more than one email.
     *
     * @return array<int, list<string>>
     */
    private function emailed(?array $extraUsers = []): array
    {
        $result = [];

        foreach ([$this->customer, $this->agent, $this->admin, ...$extraUsers] as $user) {
            $sent = Notification::sent($user, TicketActivityNotification::class)->map(fn ($n) => $n->activity->value)->all();

            if ($sent !== []) {
                $result[$user->id] = $sent;
            }

            $this->assertLessThanOrEqual(1, count($sent), "User {$user->id} got more than one email from a single request: ".implode(', ', $sent));
        }

        return $result;
    }

    /** @param array<string, string> $expected role => activity, e.g. ['customer' => 'resolved'] */
    private function assertEmailed(array $expected, array $extraUsers = []): void
    {
        $map = ['customer' => $this->customer, 'agent' => $this->agent, 'admin' => $this->admin];
        $want = [];
        foreach ($expected as $who => $activity) {
            $want[$map[$who]->id] = [$activity];
        }
        ksort($want);
        $got = $this->emailed($extraUsers);
        ksort($got);

        $this->assertSame($want, $got);
    }

    private function moveTo(Ticket $ticket, string $to)
    {
        return $this->postJson("/api/tickets/{$ticket->id}/status", ['status' => $to]);
    }

    // ---- Opening a ticket ---------------------------------------------------

    public function test_opening_a_ticket_emails_the_customer_and_the_admins_once(): void
    {
        $this->as($this->customer)->postJson('/api/tickets', ['subject' => 'Broken', 'description' => 'Help'])->assertCreated();

        $this->assertEmailed(['customer' => 'opened', 'admin' => 'new_for_staff']);
    }

    public function test_a_ticket_with_a_department_emails_only_the_customer(): void
    {
        $department = Department::create(['name' => 'Billing', 'description' => 'x', 'is_active' => true]);

        $this->as($this->customer)->postJson('/api/tickets', ['subject' => 'Broken', 'description' => 'Help', 'department_id' => $department->id])->assertCreated();

        $this->assertEmailed(['customer' => 'opened']);
    }

    public function test_a_rejected_new_ticket_emails_nobody(): void
    {
        $this->as($this->customer)->postJson('/api/tickets', ['subject' => '', 'description' => ''])->assertUnprocessable();

        $this->assertEmailed([]);
    }

    // ---- Status -------------------------------------------------------------

    public function test_staff_status_changes_email_the_customer_once_and_only_when_it_matters(): void
    {
        $cases = [
            ['open', 'resolved', 'resolved'],
            ['in_progress', 'closed', 'closed'],
            ['resolved', 'in_progress', 'reopened'],
            ['closed', 'in_progress', 'reopened'],
        ];

        foreach ($cases as [$from, $to, $activity]) {
            Notification::fake();
            $ticket = $this->ticket(['status' => $from]);
            $this->as($this->agent)->moveTo($ticket, $to)->assertOk();

            $this->assertEmailed(['customer' => $activity]);
        }

        foreach ([['open', 'in_progress'], ['in_progress', 'open']] as [$from, $to]) {
            Notification::fake();
            $ticket = $this->ticket(['status' => $from]);
            $this->as($this->agent)->moveTo($ticket, $to)->assertOk();

            $this->assertEmailed([]);
        }
    }

    public function test_an_admin_changing_status_does_not_email_themselves_or_the_assignee(): void
    {
        $this->as($this->admin)->moveTo($this->ticket(), 'resolved')->assertOk();

        $this->assertEmailed(['customer' => 'resolved']);
    }

    public function test_the_customer_closing_or_reopening_emails_the_assignee_once(): void
    {
        $this->as($this->customer)->moveTo($this->ticket(['status' => TicketStatus::Resolved]), 'closed')->assertOk();
        $this->assertEmailed(['agent' => 'customer_closed']);

        Notification::fake();
        $this->as($this->customer)->moveTo($this->ticket(['status' => TicketStatus::Resolved]), 'open')->assertOk();
        $this->assertEmailed(['agent' => 'customer_reopened']);
    }

    public function test_a_refused_or_repeated_status_change_emails_nobody(): void
    {
        $ticket = $this->ticket(['status' => TicketStatus::Open]);

        $this->as($this->customer)->moveTo($ticket, 'closed')->assertUnprocessable();   // not allowed for a customer
        $this->as($this->agent)->moveTo($ticket, 'open')->assertUnprocessable();        // already open
        $this->as($this->user(RoleName::Customer))->moveTo($ticket, 'closed')->assertForbidden();
        $this->as($this->agent)->postJson("/api/tickets/{$ticket->id}/status", ['status' => 'nonsense'])->assertUnprocessable();

        $this->assertEmailed([]);
    }

    public function test_pressing_the_same_status_twice_emails_only_for_the_change_that_happened(): void
    {
        $ticket = $this->ticket(['status' => TicketStatus::Open]);

        $this->as($this->agent)->moveTo($ticket, 'resolved')->assertOk();
        $this->moveTo($ticket, 'resolved')->assertUnprocessable();

        $this->assertEmailed(['customer' => 'resolved']);
    }

    // ---- Assignment and priority --------------------------------------------

    public function test_assigning_emails_the_new_assignee_once(): void
    {
        $ticket = $this->ticket(['assigned_to' => null]);

        $this->as($this->admin)->putJson("/api/tickets/{$ticket->id}/assignee", ['assignee_id' => $this->agent->id])->assertOk();

        $this->assertEmailed(['agent' => 'assigned']);
    }

    public function test_assigning_the_same_agent_again_unassigning_and_priority_changes_email_nobody(): void
    {
        $ticket = $this->ticket();
        $this->as($this->admin);

        $this->putJson("/api/tickets/{$ticket->id}/assignee", ['assignee_id' => $this->agent->id])->assertOk(); // unchanged
        $this->putJson("/api/tickets/{$ticket->id}/priority", ['priority' => 'urgent'])->assertOk();
        $this->putJson("/api/tickets/{$ticket->id}/assignee", ['assignee_id' => null])->assertOk();

        $this->assertEmailed([]);
    }

    public function test_reassigning_emails_only_the_new_agent(): void
    {
        $other = $this->user(RoleName::Agent);
        $ticket = $this->ticket();

        $this->as($this->admin)->putJson("/api/tickets/{$ticket->id}/assignee", ['assignee_id' => $other->id])->assertOk();

        $this->assertSame([], $this->emailed()); // nothing to the old assignee, the customer or the admin
        Notification::assertSentTo($other, TicketActivityNotification::class, fn ($n) => $n->activity === TicketActivity::Assigned);
        Notification::assertSentToTimes($other, TicketActivityNotification::class, 1);
    }

    public function test_a_refused_assignment_emails_nobody(): void
    {
        $ticket = $this->ticket(['assigned_to' => null]);

        $this->as($this->admin)->putJson("/api/tickets/{$ticket->id}/assignee", ['assignee_id' => $this->customer->id])->assertUnprocessable();
        $this->as($this->agent)->putJson("/api/tickets/{$ticket->id}/assignee", ['assignee_id' => $this->agent->id])->assertForbidden();
        $closed = $this->ticket(['status' => TicketStatus::Closed, 'assigned_to' => null]);
        $this->as($this->admin)->putJson("/api/tickets/{$closed->id}/assignee", ['assignee_id' => $this->agent->id])->assertUnprocessable();

        $this->assertEmailed([]);
    }

    // ---- Replies and notes --------------------------------------------------

    public function test_a_staff_reply_emails_the_customer_once(): void
    {
        $this->as($this->agent)->postJson("/api/tickets/{$this->ticket()->id}/replies", ['body' => 'Hello'])->assertCreated();

        $this->assertEmailed(['customer' => 'staff_reply']);
    }

    public function test_a_customer_reply_emails_the_assignee_once(): void
    {
        $this->as($this->customer)->postJson("/api/tickets/{$this->ticket()->id}/replies", ['body' => 'Hello'])->assertCreated();

        $this->assertEmailed(['agent' => 'customer_reply']);
    }

    public function test_a_customer_reply_on_an_unassigned_ticket_emails_the_admins(): void
    {
        $this->as($this->customer)->postJson("/api/tickets/{$this->ticket(['assigned_to' => null])->id}/replies", ['body' => 'Hello'])->assertCreated();

        $this->assertEmailed(['admin' => 'customer_reply']);
    }

    public function test_a_customer_reply_that_reopens_a_resolved_ticket_is_one_email_not_two(): void
    {
        $ticket = $this->ticket(['status' => TicketStatus::Resolved, 'resolved_at' => now()]);

        $this->as($this->customer)->postJson("/api/tickets/{$ticket->id}/replies", ['body' => 'Still broken'])
            ->assertCreated()->assertJsonPath('ticket.status', 'open');

        // One email for the assignee, and it is the combined one: no separate reply + reopen emails.
        $this->assertEmailed(['agent' => 'customer_reply_reopened']);
        Notification::assertSentToTimes($this->agent, TicketActivityNotification::class, 1);
    }

    public function test_a_staff_reply_on_a_resolved_ticket_does_not_reopen_or_double_email(): void
    {
        $ticket = $this->ticket(['status' => TicketStatus::Resolved, 'resolved_at' => now()]);

        $this->as($this->agent)->postJson("/api/tickets/{$ticket->id}/replies", ['body' => 'Any news?'])->assertCreated();

        $this->assertEmailed(['customer' => 'staff_reply']);
    }

    public function test_an_internal_note_emails_the_assignee_and_never_the_customer(): void
    {
        $ticket = $this->ticket();

        $this->as($this->admin)->postJson("/api/tickets/{$ticket->id}/internal-notes", ['body' => 'Careful with this one'])->assertCreated();
        $this->assertEmailed(['agent' => 'internal_note']);

        Notification::fake();
        $this->as($this->agent)->postJson("/api/tickets/{$ticket->id}/internal-notes", ['body' => 'My own note'])->assertCreated();
        $this->assertEmailed([]); // the assignee wrote it; nobody else is told

        Notification::fake();
        $this->as($this->admin)->postJson("/api/tickets/{$this->ticket(['assigned_to' => null])->id}/internal-notes", ['body' => 'Nobody assigned'])->assertCreated();
        $this->assertEmailed([]);
    }

    public function test_failed_or_refused_messages_email_nobody(): void
    {
        $ticket = $this->ticket();
        $closed = $this->ticket(['status' => TicketStatus::Closed, 'closed_at' => now()]);

        $this->as($this->customer)->postJson("/api/tickets/{$ticket->id}/replies", ['body' => '   '])->assertUnprocessable();
        $this->postJson("/api/tickets/{$closed->id}/replies", ['body' => 'late'])->assertUnprocessable();
        $this->postJson("/api/tickets/{$ticket->id}/internal-notes", ['body' => 'x'])->assertForbidden();
        $this->as($this->user(RoleName::Customer))->postJson("/api/tickets/{$ticket->id}/replies", ['body' => 'x'])->assertForbidden();
        $this->as($this->agent)->postJson("/api/tickets/{$closed->id}/internal-notes", ['body' => 'late note'])->assertUnprocessable();

        $this->assertEmailed([]);
    }

    // ---- Rollback and switches ---------------------------------------------

    public function test_nothing_is_emailed_when_the_request_fails_and_rolls_back(): void
    {
        AuditLog::creating(fn () => throw new \RuntimeException('audit table is unavailable'));

        $ticket = $this->ticket(['status' => TicketStatus::Open, 'assigned_to' => null]);
        $mine = $this->ticket(['status' => TicketStatus::Open]); // assigned to the agent
        $replyTicket = $this->ticket();
        $resolved = $this->ticket(['status' => TicketStatus::Resolved, 'resolved_at' => now()]);

        $this->as($this->customer)->postJson('/api/tickets', ['subject' => 'New', 'description' => 'Body'])->assertStatus(500);
        $this->as($this->agent)->moveTo($mine, 'resolved')->assertStatus(500);
        $this->as($this->admin)->putJson("/api/tickets/{$ticket->id}/assignee", ['assignee_id' => $this->agent->id])->assertStatus(500);
        $this->as($this->agent)->postJson("/api/tickets/{$replyTicket->id}/replies", ['body' => 'Hello'])->assertStatus(500);
        $this->as($this->admin)->postJson("/api/tickets/{$replyTicket->id}/internal-notes", ['body' => 'Note'])->assertStatus(500);
        $this->as($this->customer)->postJson("/api/tickets/{$resolved->id}/replies", ['body' => 'Back again'])->assertStatus(500);

        $this->assertEmailed([]);
        // ...and the changes were rolled back with it.
        $this->assertSame(TicketStatus::Open, $mine->fresh()->status);
        $this->assertNull($ticket->fresh()->assigned_to);
        $this->assertSame(TicketStatus::Resolved, $resolved->fresh()->status);
        $this->assertSame(0, $replyTicket->messages()->count());
        $this->assertSame(0, Ticket::where('subject', 'New')->count());
    }

    public function test_the_off_switch_silences_every_endpoint_but_changes_nothing_else(): void
    {
        config(['helpdesk.ticket_notifications' => false]);
        $ticket = $this->ticket(['status' => TicketStatus::Open, 'assigned_to' => null]);

        $this->as($this->customer)->postJson('/api/tickets', ['subject' => 'New', 'description' => 'Body'])->assertCreated();
        $this->as($this->admin)->putJson("/api/tickets/{$ticket->id}/assignee", ['assignee_id' => $this->agent->id])->assertOk();
        $this->as($this->agent)->moveTo($ticket, 'resolved')->assertOk();
        $this->postJson("/api/tickets/{$ticket->id}/replies", ['body' => 'Hi'])->assertCreated();

        Notification::assertNothingSent();
        $this->assertSame(TicketStatus::Resolved, $ticket->fresh()->status);
    }

    public function test_a_broken_email_system_never_breaks_the_ticket_operation(): void
    {
        Notification::shouldReceive('send')->andThrow(new \RuntimeException('queue is down'));
        $ticket = $this->ticket();

        $this->as($this->agent)->moveTo($ticket, 'resolved')->assertOk()->assertJsonPath('ticket.status', 'resolved');
        $this->postJson("/api/tickets/{$ticket->id}/replies", ['body' => 'Hi'])->assertCreated();
        $this->as($this->customer)->postJson('/api/tickets', ['subject' => 'New', 'description' => 'Body'])->assertCreated();
    }

    public function test_with_a_real_queue_each_email_is_one_job_for_one_person(): void
    {
        // Use the real notification system (setUp faked it) on a faked queue.
        Notification::clearResolvedInstances();
        app()->forgetInstance(ChannelManager::class);
        Queue::fake();
        $ticket = $this->ticket(['status' => TicketStatus::Resolved, 'resolved_at' => now()]);

        $this->as($this->customer)->postJson("/api/tickets/{$ticket->id}/replies", ['body' => 'Still broken'])->assertCreated();

        Queue::assertPushed(SendQueuedNotifications::class, 1);
    }
}
