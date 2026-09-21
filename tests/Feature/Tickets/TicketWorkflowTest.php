<?php

namespace Tests\Feature\Tickets;

use App\Enums\RoleName;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Enums\UserStatus;
use App\Models\Department;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TicketWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function makeUser(RoleName $role, array $attributes = []): User
    {
        return User::factory()->create($attributes)->assignRole($role->value);
    }

    private function actingAsRole(RoleName $role): User
    {
        $user = $this->makeUser($role);
        Sanctum::actingAs($user);

        return $user;
    }

    private function ticket(array $attributes = [], ?User $customer = null): Ticket
    {
        return Ticket::factory()->create(array_merge(['customer_id' => ($customer ?? $this->makeUser(RoleName::Customer))->id], $attributes));
    }

    private function setStatus(Ticket $ticket, string $status)
    {
        return $this->postJson("/api/tickets/{$ticket->id}/status", ['status' => $status]);
    }

    // ---- Status: staff -------------------------------------------------------

    public static function staffTransitions(): array
    {
        $all = ['open', 'in_progress', 'resolved', 'closed'];
        $allowed = [
            'open' => ['in_progress', 'resolved', 'closed'],
            'in_progress' => ['open', 'resolved', 'closed'],
            'resolved' => ['in_progress', 'closed'],
            'closed' => ['in_progress'],
        ];

        $cases = [];
        foreach ($all as $from) {
            foreach ($all as $to) {
                $cases["{$from} -> {$to}"] = [$from, $to, in_array($to, $allowed[$from], true)];
            }
        }

        return $cases;
    }

    #[DataProvider('staffTransitions')]
    public function test_staff_transitions_follow_the_workflow(string $from, string $to, bool $allowed): void
    {
        $this->actingAsRole(RoleName::Admin);
        $ticket = $this->ticket(['status' => TicketStatus::from($from)]);

        $response = $this->setStatus($ticket, $to);

        if ($allowed) {
            $response->assertOk()->assertJsonPath('ticket.status', $to);
            $this->assertSame($to, $ticket->fresh()->status->value);
        } else {
            $response->assertUnprocessable()->assertJsonPath('code', 'invalid_transition');
            $this->assertSame($from, $ticket->fresh()->status->value);
        }
    }

    public function test_resolved_and_closed_timestamps_follow_the_status(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $ticket = $this->ticket(['status' => TicketStatus::InProgress]);

        $this->setStatus($ticket, 'resolved')->assertOk();
        $this->assertNotNull($ticket->fresh()->resolved_at);
        $this->assertNull($ticket->fresh()->closed_at);

        $this->setStatus($ticket, 'closed')->assertOk();
        $this->assertNotNull($ticket->fresh()->closed_at);
        $this->assertNotNull($ticket->fresh()->resolved_at); // still resolved before it was closed

        $this->setStatus($ticket, 'in_progress')->assertOk(); // reopened
        $this->assertNull($ticket->fresh()->closed_at);
        $this->assertNull($ticket->fresh()->resolved_at);
    }

    public function test_an_invalid_status_value_is_rejected(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $ticket = $this->ticket();

        $this->setStatus($ticket, 'nonsense')->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->postJson("/api/tickets/{$ticket->id}/status", [])->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    public function test_the_response_lists_the_allowed_moves_on_a_refusal(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $ticket = $this->ticket(['status' => TicketStatus::Closed]);

        $this->setStatus($ticket, 'resolved')->assertUnprocessable()->assertJsonPath('allowed', ['in_progress']);
    }

    public function test_status_changes_are_recorded_in_the_history_and_the_audit_log(): void
    {
        $admin = $this->actingAsRole(RoleName::Admin);
        $ticket = $this->ticket();

        $this->setStatus($ticket, 'in_progress')->assertOk();

        $this->assertDatabaseHas('ticket_events', ['ticket_id' => $ticket->id, 'actor_id' => $admin->id, 'type' => 'status_changed']);
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $admin->id, 'action' => 'ticket.status_changed', 'subject_id' => $ticket->id]);
        $event = $ticket->events()->sole();
        $this->assertSame(['from' => 'open', 'to' => 'in_progress'], $event->data);
    }

    public function test_a_refused_change_leaves_no_history(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $ticket = $this->ticket(['status' => TicketStatus::Closed]);

        $this->setStatus($ticket, 'open')->assertUnprocessable();

        $this->assertDatabaseCount('ticket_events', 0);
    }

    // ---- Status: agent ---------------------------------------------------------

    public function test_an_agent_can_work_their_assigned_tickets_but_not_others(): void
    {
        $agent = $this->actingAsRole(RoleName::Agent);
        $mine = $this->ticket(['assigned_to' => $agent->id]);
        $theirs = $this->ticket(['assigned_to' => $this->makeUser(RoleName::Agent)->id]);
        $unassigned = $this->ticket();

        $this->setStatus($mine, 'in_progress')->assertOk();
        $this->setStatus($theirs, 'in_progress')->assertForbidden();
        $this->setStatus($unassigned, 'in_progress')->assertForbidden();
    }

    // ---- Status: customer ------------------------------------------------------

    public function test_a_customer_can_close_or_reopen_their_resolved_ticket(): void
    {
        $customer = $this->actingAsRole(RoleName::Customer);
        $closable = $this->ticket(['status' => TicketStatus::Resolved], $customer);
        $reopenable = $this->ticket(['status' => TicketStatus::Resolved], $customer);

        $this->setStatus($closable, 'closed')->assertOk()->assertJsonPath('ticket.status', 'closed');
        $this->setStatus($reopenable, 'open')->assertOk()->assertJsonPath('ticket.status', 'open');
        $this->assertNull($reopenable->fresh()->resolved_at);
    }

    public function test_a_customer_cannot_make_any_other_move(): void
    {
        $customer = $this->actingAsRole(RoleName::Customer);

        foreach ([
            ['open', 'closed'], ['open', 'resolved'], ['open', 'in_progress'],
            ['in_progress', 'closed'], ['in_progress', 'open'],
            ['resolved', 'in_progress'],
            ['closed', 'open'], ['closed', 'in_progress'],
        ] as [$from, $to]) {
            $ticket = $this->ticket(['status' => TicketStatus::from($from)], $customer);

            $this->setStatus($ticket, $to)->assertUnprocessable()->assertJsonPath('code', 'invalid_transition');
            $this->assertSame($from, $ticket->fresh()->status->value, "{$from} -> {$to}");
        }
    }

    public function test_a_customer_cannot_touch_another_customers_ticket(): void
    {
        $this->actingAsRole(RoleName::Customer);
        $theirs = $this->ticket(['status' => TicketStatus::Resolved]);

        $this->setStatus($theirs, 'closed')->assertForbidden();
        $this->assertSame(TicketStatus::Resolved, $theirs->fresh()->status);
    }

    public function test_a_super_admin_can_move_any_ticket(): void
    {
        $this->actingAsRole(RoleName::SuperAdmin);

        $this->setStatus($this->ticket(), 'resolved')->assertOk();
    }

    // ---- Priority ----------------------------------------------------------------

    public function test_an_admin_can_change_priority_and_it_is_recorded(): void
    {
        $admin = $this->actingAsRole(RoleName::Admin);
        $ticket = $this->ticket();

        $this->putJson("/api/tickets/{$ticket->id}/priority", ['priority' => 'urgent'])
            ->assertOk()->assertJsonPath('ticket.priority', 'urgent');

        $this->assertSame(['from' => 'medium', 'to' => 'urgent'], $ticket->events()->sole()->data);
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $admin->id, 'action' => 'ticket.priority_changed']);
    }

    public function test_setting_the_same_priority_records_nothing(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $ticket = $this->ticket(['priority' => TicketPriority::High]);

        $this->putJson("/api/tickets/{$ticket->id}/priority", ['priority' => 'high'])->assertOk();

        $this->assertDatabaseCount('ticket_events', 0);
    }

    public function test_agents_and_customers_cannot_change_priority_by_default(): void
    {
        $agent = $this->makeUser(RoleName::Agent);
        $customer = $this->makeUser(RoleName::Customer);
        $ticket = $this->ticket(['assigned_to' => $agent->id], $customer);

        foreach ([$agent, $customer] as $user) {
            Sanctum::actingAs($user);
            $this->putJson("/api/tickets/{$ticket->id}/priority", ['priority' => 'urgent'])->assertForbidden();
        }

        $this->assertSame(TicketPriority::Medium, $ticket->fresh()->priority);
    }

    public function test_an_agent_granted_the_permission_can_change_priority_on_their_tickets_only(): void
    {
        $agent = $this->makeUser(RoleName::Agent);
        $agent->givePermissionTo('ticket.update_priority');
        Sanctum::actingAs($agent);

        $mine = $this->ticket(['assigned_to' => $agent->id]);
        $other = $this->ticket();

        $this->putJson("/api/tickets/{$mine->id}/priority", ['priority' => 'high'])->assertOk();
        $this->putJson("/api/tickets/{$other->id}/priority", ['priority' => 'high'])->assertForbidden();
    }

    public function test_an_invalid_priority_is_rejected(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $ticket = $this->ticket();

        $this->putJson("/api/tickets/{$ticket->id}/priority", ['priority' => 'critical'])->assertUnprocessable()->assertJsonValidationErrors('priority');
    }

    // ---- Assignment ----------------------------------------------------------------

    public function test_an_admin_assigns_an_agent_who_then_sees_the_ticket(): void
    {
        $admin = $this->makeUser(RoleName::Admin);
        $agent = $this->makeUser(RoleName::Agent, ['name' => 'Alex Agent']);
        $ticket = $this->ticket();
        Sanctum::actingAs($agent);
        $this->getJson("/api/tickets/{$ticket->id}")->assertForbidden();

        Sanctum::actingAs($admin);
        $this->putJson("/api/tickets/{$ticket->id}/assignee", ['assignee_id' => $agent->id])
            ->assertOk()->assertJsonPath('ticket.assignee.name', 'Alex Agent');

        Sanctum::actingAs($agent);
        $this->getJson("/api/tickets/{$ticket->id}")->assertOk();
        $this->getJson('/api/tickets')->assertOk()->assertJsonCount(1, 'data');
        $event = $ticket->events()->sole();
        $this->assertSame('assigned', $event->type);
        $this->assertSame('Alex Agent', $event->data['assignee_name']);
        $this->assertSame($admin->id, $event->actor_id);
    }

    public function test_only_active_agents_can_be_assigned(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $ticket = $this->ticket();

        foreach ([
            $this->makeUser(RoleName::Customer),
            $this->makeUser(RoleName::Admin),
            $this->makeUser(RoleName::Agent, ['status' => UserStatus::Inactive]),
        ] as $notEligible) {
            $this->putJson("/api/tickets/{$ticket->id}/assignee", ['assignee_id' => $notEligible->id])
                ->assertUnprocessable()->assertJsonValidationErrors('assignee_id');
        }
        $this->putJson("/api/tickets/{$ticket->id}/assignee", ['assignee_id' => 999999])->assertUnprocessable();

        $this->assertNull($ticket->fresh()->assigned_to);
        $this->assertDatabaseCount('ticket_events', 0);
    }

    public function test_with_a_department_the_agent_must_be_a_member(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $department = Department::factory()->create();
        $member = $this->makeUser(RoleName::Agent);
        $outsider = $this->makeUser(RoleName::Agent);
        $department->users()->attach($member);
        $ticket = $this->ticket(['department_id' => $department->id]);

        $this->putJson("/api/tickets/{$ticket->id}/assignee", ['assignee_id' => $outsider->id])
            ->assertUnprocessable()->assertJsonValidationErrors('assignee_id');
        $this->putJson("/api/tickets/{$ticket->id}/assignee", ['assignee_id' => $member->id])->assertOk();
    }

    public function test_without_a_department_any_active_agent_will_do(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $ticket = $this->ticket(['department_id' => null]);

        $this->putJson("/api/tickets/{$ticket->id}/assignee", ['assignee_id' => $this->makeUser(RoleName::Agent)->id])->assertOk();
    }

    public function test_a_ticket_can_be_reassigned_and_unassigned(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $first = $this->makeUser(RoleName::Agent, ['name' => 'First']);
        $second = $this->makeUser(RoleName::Agent, ['name' => 'Second']);
        $ticket = $this->ticket(['assigned_to' => $first->id]);

        $this->putJson("/api/tickets/{$ticket->id}/assignee", ['assignee_id' => $second->id])->assertOk();
        $this->assertSame($second->id, $ticket->fresh()->assigned_to);

        $this->putJson("/api/tickets/{$ticket->id}/assignee", ['assignee_id' => null])
            ->assertOk()->assertJsonPath('ticket.assignee', null);
        $this->assertNull($ticket->fresh()->assigned_to);

        $this->assertSame(['assigned', 'unassigned'], $ticket->events()->orderBy('id')->pluck('type')->all());
    }

    public function test_assigning_the_same_agent_again_records_nothing(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $agent = $this->makeUser(RoleName::Agent);
        $ticket = $this->ticket(['assigned_to' => $agent->id]);

        $this->putJson("/api/tickets/{$ticket->id}/assignee", ['assignee_id' => $agent->id])->assertOk();
        $this->putJson("/api/tickets/{$ticket->id}/assignee", ['assignee_id' => null])->assertOk();
        $this->putJson("/api/tickets/{$ticket->id}/assignee", ['assignee_id' => null])->assertOk();

        $this->assertDatabaseCount('ticket_events', 1); // only the first unassign
    }

    public function test_the_assignee_field_must_be_present(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $ticket = $this->ticket();

        $this->putJson("/api/tickets/{$ticket->id}/assignee", [])->assertUnprocessable()->assertJsonValidationErrors('assignee_id');
    }

    public function test_agents_and_customers_cannot_assign(): void
    {
        $agent = $this->makeUser(RoleName::Agent);
        $customer = $this->makeUser(RoleName::Customer);
        $ticket = $this->ticket(['assigned_to' => $agent->id], $customer);

        foreach ([$agent, $customer] as $user) {
            Sanctum::actingAs($user);
            $this->putJson("/api/tickets/{$ticket->id}/assignee", ['assignee_id' => $agent->id])->assertForbidden();
            $this->getJson("/api/tickets/{$ticket->id}/assignable-agents")->assertForbidden();
        }
    }

    public function test_assignable_agents_respect_the_ticket_department(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $department = Department::factory()->create();
        $member = $this->makeUser(RoleName::Agent, ['name' => 'Member']);
        $this->makeUser(RoleName::Agent, ['name' => 'Outsider']);
        $this->makeUser(RoleName::Agent, ['name' => 'Inactive Member', 'status' => UserStatus::Inactive]);
        $department->users()->attach($member);

        $routed = $this->ticket(['department_id' => $department->id]);
        $unrouted = $this->ticket();

        $this->getJson("/api/tickets/{$routed->id}/assignable-agents")->assertOk()->assertJsonCount(1, 'agents')->assertJsonPath('agents.0.name', 'Member');
        $names = collect($this->getJson("/api/tickets/{$unrouted->id}/assignable-agents")->json('agents'))->pluck('name');
        $this->assertEqualsCanonicalizing(['Member', 'Outsider'], $names->all());
    }

    // ---- Closed tickets ---------------------------------------------------------------

    public function test_a_closed_ticket_cannot_be_reprioritised_or_reassigned(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $agent = $this->makeUser(RoleName::Agent);
        $ticket = $this->ticket(['status' => TicketStatus::Closed]);

        $this->putJson("/api/tickets/{$ticket->id}/priority", ['priority' => 'high'])->assertUnprocessable()->assertJsonPath('code', 'ticket_closed');
        $this->putJson("/api/tickets/{$ticket->id}/assignee", ['assignee_id' => $agent->id])->assertUnprocessable()->assertJsonPath('code', 'ticket_closed');
        // ...but it can be reopened, after which changes work again.
        $this->setStatus($ticket, 'in_progress')->assertOk();
        $this->putJson("/api/tickets/{$ticket->id}/priority", ['priority' => 'high'])->assertOk();
    }

    // ---- Abilities and history in the detail response ------------------------------------

    public function test_the_detail_response_tells_each_viewer_what_they_may_do(): void
    {
        $agent = $this->makeUser(RoleName::Agent);
        $customer = $this->makeUser(RoleName::Customer);
        $ticket = $this->ticket(['status' => TicketStatus::Resolved, 'assigned_to' => $agent->id], $customer);

        Sanctum::actingAs($this->makeUser(RoleName::Admin));
        $this->getJson("/api/tickets/{$ticket->id}")->assertOk()
            ->assertJsonPath('abilities.can_change_status', true)
            ->assertJsonPath('abilities.can_change_priority', true)
            ->assertJsonPath('abilities.can_assign', true)
            ->assertJsonPath('abilities.allowed_statuses', ['in_progress', 'closed']);

        Sanctum::actingAs($agent);
        $this->getJson("/api/tickets/{$ticket->id}")->assertOk()
            ->assertJsonPath('abilities.can_change_status', true)
            ->assertJsonPath('abilities.can_change_priority', false)
            ->assertJsonPath('abilities.can_assign', false);

        Sanctum::actingAs($customer);
        $this->getJson("/api/tickets/{$ticket->id}")->assertOk()
            ->assertJsonPath('abilities.allowed_statuses', ['closed', 'open'])
            ->assertJsonPath('abilities.can_change_priority', false)
            ->assertJsonPath('abilities.can_assign', false);
    }

    public function test_a_customer_has_no_abilities_on_an_open_ticket_and_closed_tickets_are_read_only(): void
    {
        $customer = $this->makeUser(RoleName::Customer);
        $open = $this->ticket(['status' => TicketStatus::Open], $customer);
        Sanctum::actingAs($customer);
        $this->getJson("/api/tickets/{$open->id}")->assertOk()
            ->assertJsonPath('abilities.can_change_status', false)
            ->assertJsonPath('abilities.allowed_statuses', []);

        $closed = $this->ticket(['status' => TicketStatus::Closed]);
        Sanctum::actingAs($this->makeUser(RoleName::Admin));
        $this->getJson("/api/tickets/{$closed->id}")->assertOk()
            ->assertJsonPath('abilities.can_change_priority', false)
            ->assertJsonPath('abilities.can_assign', false)
            ->assertJsonPath('abilities.allowed_statuses', ['in_progress']);
    }

    public function test_history_is_shown_oldest_first_with_actor_names_and_is_visible_to_the_customer(): void
    {
        $admin = $this->makeUser(RoleName::Admin, ['name' => 'Ada Admin']);
        $agent = $this->makeUser(RoleName::Agent, ['name' => 'Alex Agent']);
        $customer = $this->makeUser(RoleName::Customer);
        $ticket = $this->ticket([], $customer);

        Sanctum::actingAs($admin);
        $this->putJson("/api/tickets/{$ticket->id}/assignee", ['assignee_id' => $agent->id])->assertOk();
        $this->putJson("/api/tickets/{$ticket->id}/priority", ['priority' => 'high'])->assertOk();
        $this->setStatus($ticket, 'in_progress')->assertOk();

        Sanctum::actingAs($customer);
        $response = $this->getJson("/api/tickets/{$ticket->id}")->assertOk();

        $this->assertSame(['assigned', 'priority_changed', 'status_changed'], collect($response->json('events'))->pluck('type')->all());
        $response->assertJsonPath('events.0.actor.name', 'Ada Admin')
            ->assertJsonPath('events.0.assignee_name', 'Alex Agent')
            ->assertJsonPath('events.2.from', 'open')
            ->assertJsonPath('events.2.to', 'in_progress');
    }

    public function test_history_survives_the_actor_being_removed_and_is_deleted_with_the_ticket(): void
    {
        $admin = $this->actingAsRole(RoleName::Admin);
        $ticket = $this->ticket();
        $this->setStatus($ticket, 'in_progress')->assertOk();

        $admin->delete();
        $this->assertNull($ticket->events()->sole()->actor_id);

        $ticket->delete();
        $this->assertDatabaseCount('ticket_events', 0);
    }

    public function test_the_new_endpoints_require_authentication(): void
    {
        $ticket = $this->ticket();

        $this->postJson("/api/tickets/{$ticket->id}/status", ['status' => 'closed'])->assertUnauthorized();
        $this->putJson("/api/tickets/{$ticket->id}/priority", ['priority' => 'high'])->assertUnauthorized();
        $this->putJson("/api/tickets/{$ticket->id}/assignee", ['assignee_id' => null])->assertUnauthorized();
        $this->getJson("/api/tickets/{$ticket->id}/assignable-agents")->assertUnauthorized();
    }
}
