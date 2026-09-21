<?php

namespace Tests\Feature\Tickets;

use App\Enums\RoleName;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\Department;
use App\Models\Ticket;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TicketTest extends TestCase
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

    private function ticketFor(User $customer, array $attributes = []): Ticket
    {
        return Ticket::factory()->create(array_merge(['customer_id' => $customer->id], $attributes));
    }

    // ---- Authentication ------------------------------------------------------

    public function test_endpoints_require_authentication(): void
    {
        $ticket = Ticket::factory()->create();

        $this->getJson('/api/tickets')->assertUnauthorized();
        $this->postJson('/api/tickets', [])->assertUnauthorized();
        $this->getJson('/api/tickets/options')->assertUnauthorized();
        $this->getJson("/api/tickets/{$ticket->id}")->assertUnauthorized();
    }

    // ---- Creating ------------------------------------------------------------

    public function test_a_customer_can_open_a_ticket(): void
    {
        $customer = $this->actingAsRole(RoleName::Customer);
        $department = Department::factory()->create(['name' => 'Billing']);

        $response = $this->postJson('/api/tickets', [
            'subject' => '  Cannot log in  ',
            'description' => 'It says my password is wrong.',
            'department_id' => $department->id,
        ]);

        $response->assertCreated()
            ->assertJsonPath('ticket.subject', 'Cannot log in')
            ->assertJsonPath('ticket.status', 'open')
            ->assertJsonPath('ticket.priority', 'medium')
            ->assertJsonPath('ticket.department.name', 'Billing')
            ->assertJsonPath('ticket.customer.id', $customer->id)
            ->assertJsonPath('ticket.assignee', null);

        $ticket = Ticket::sole();
        $this->assertSame($customer->id, $ticket->customer_id);
        $this->assertSame('TCK-'.str_pad((string) $ticket->id, 6, '0', STR_PAD_LEFT), $response->json('ticket.number'));
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $customer->id, 'action' => 'ticket.created', 'subject_id' => $ticket->id]);
    }

    public function test_the_department_is_optional(): void
    {
        $this->actingAsRole(RoleName::Customer);

        $this->postJson('/api/tickets', ['subject' => 'Hi', 'description' => 'Hello'])
            ->assertCreated()->assertJsonPath('ticket.department', null);
    }

    public function test_privileged_fields_cannot_be_smuggled_in(): void
    {
        $customer = $this->actingAsRole(RoleName::Customer);
        $other = $this->makeUser(RoleName::Customer);
        $agent = $this->makeUser(RoleName::Agent);

        $this->postJson('/api/tickets', [
            'subject' => 'Sneaky', 'description' => 'x',
            'status' => 'closed', 'priority' => 'urgent', 'customer_id' => $other->id, 'assigned_to' => $agent->id,
            'resolved_at' => now()->toDateTimeString(),
        ])->assertCreated();

        $ticket = Ticket::sole();
        $this->assertSame(TicketStatus::Open, $ticket->status);
        $this->assertSame(TicketPriority::Medium, $ticket->priority);
        $this->assertSame($customer->id, $ticket->customer_id);
        $this->assertNull($ticket->assigned_to);
        $this->assertNull($ticket->resolved_at);
    }

    public function test_creation_is_validated(): void
    {
        $this->actingAsRole(RoleName::Customer);
        $inactive = Department::factory()->inactive()->create();

        $this->postJson('/api/tickets', [])->assertUnprocessable()->assertJsonValidationErrors(['subject', 'description']);
        $this->postJson('/api/tickets', ['subject' => str_repeat('a', 151), 'description' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('subject');
        $this->postJson('/api/tickets', ['subject' => 'x', 'description' => str_repeat('a', 5001)])->assertUnprocessable()->assertJsonValidationErrors('description');
        $this->postJson('/api/tickets', ['subject' => '   ', 'description' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('subject');
        $this->postJson('/api/tickets', ['subject' => 'x', 'description' => 'x', 'department_id' => 999999])->assertUnprocessable()->assertJsonValidationErrors('department_id');
        $this->postJson('/api/tickets', ['subject' => 'x', 'description' => 'x', 'department_id' => $inactive->id])->assertUnprocessable()->assertJsonValidationErrors('department_id');

        $this->assertDatabaseCount('tickets', 0);
    }

    public function test_only_users_with_the_create_permission_can_open_tickets(): void
    {
        foreach ([RoleName::Agent, RoleName::Admin] as $role) {
            $this->actingAsRole($role);
            $this->postJson('/api/tickets', ['subject' => 'x', 'description' => 'x'])->assertForbidden();
            $this->getJson('/api/tickets/options')->assertForbidden();
        }

        $this->assertDatabaseCount('tickets', 0);
    }

    public function test_opening_tickets_is_rate_limited_per_user(): void
    {
        $this->actingAsRole(RoleName::Customer);

        for ($i = 0; $i < 30; $i++) {
            $this->postJson('/api/tickets', ['subject' => "T{$i}", 'description' => 'x'])->assertCreated();
        }

        $this->postJson('/api/tickets', ['subject' => 'one too many', 'description' => 'x'])->assertStatus(429);
        $this->assertDatabaseCount('tickets', 30);
    }

    public function test_options_list_only_active_departments(): void
    {
        $this->actingAsRole(RoleName::Customer);
        Department::factory()->create(['name' => 'Billing']);
        Department::factory()->inactive()->create(['name' => 'Archive']);

        $names = collect($this->getJson('/api/tickets/options')->assertOk()->json('departments'))->pluck('name');

        $this->assertSame(['Billing'], $names->all());
    }

    // ---- Visibility ----------------------------------------------------------

    public function test_a_customer_sees_only_their_own_tickets(): void
    {
        $me = $this->actingAsRole(RoleName::Customer);
        $mine = $this->ticketFor($me, ['subject' => 'Mine']);
        $theirs = $this->ticketFor($this->makeUser(RoleName::Customer), ['subject' => 'Theirs']);

        $this->getJson('/api/tickets')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.subject', 'Mine');
        $this->getJson("/api/tickets/{$mine->id}")->assertOk()->assertJsonPath('ticket.subject', 'Mine');
        $this->getJson("/api/tickets/{$theirs->id}")->assertForbidden();
    }

    public function test_an_agent_sees_only_tickets_assigned_to_them(): void
    {
        $agent = $this->actingAsRole(RoleName::Agent);
        $customer = $this->makeUser(RoleName::Customer);
        $department = Department::factory()->create();
        $department->users()->attach($agent);
        $assigned = $this->ticketFor($customer, ['subject' => 'Assigned', 'assigned_to' => $agent->id, 'department_id' => $department->id]);
        $unassigned = $this->ticketFor($customer, ['subject' => 'Unassigned', 'department_id' => $department->id]);
        $others = $this->ticketFor($customer, ['subject' => 'Someone else', 'assigned_to' => $this->makeUser(RoleName::Agent)->id]);

        $this->getJson('/api/tickets')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.subject', 'Assigned');
        $this->getJson("/api/tickets/{$assigned->id}")->assertOk();
        // Being in the ticket's department is not enough.
        $this->getJson("/api/tickets/{$unassigned->id}")->assertForbidden();
        $this->getJson("/api/tickets/{$others->id}")->assertForbidden();
    }

    public function test_admins_and_super_admins_see_every_ticket(): void
    {
        $customer = $this->makeUser(RoleName::Customer);
        $this->ticketFor($customer);
        $this->ticketFor($this->makeUser(RoleName::Customer));

        foreach ([RoleName::Admin, RoleName::SuperAdmin] as $role) {
            $this->actingAsRole($role);
            $this->getJson('/api/tickets')->assertOk()->assertJsonCount(2, 'data');
        }
    }

    public function test_a_view_all_permission_widens_an_agents_view(): void
    {
        $agent = $this->makeUser(RoleName::Agent);
        $agent->givePermissionTo('ticket.view_all');
        Sanctum::actingAs($agent);
        $this->ticketFor($this->makeUser(RoleName::Customer));

        $this->getJson('/api/tickets')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_missing_tickets_return_404(): void
    {
        $this->actingAsRole(RoleName::Admin);

        $this->getJson('/api/tickets/999999')->assertNotFound();
    }

    // ---- Listing -------------------------------------------------------------

    public function test_listing_shape_and_privacy(): void
    {
        $admin = $this->actingAsRole(RoleName::Admin);
        $agent = $this->makeUser(RoleName::Agent, ['name' => 'Alex Agent']);
        $customer = $this->makeUser(RoleName::Customer, ['name' => 'Cara Customer']);
        $department = Department::factory()->create(['name' => 'Billing']);
        $this->ticketFor($customer, ['assigned_to' => $agent->id, 'department_id' => $department->id]);

        $response = $this->getJson('/api/tickets')->assertOk();

        $response->assertJsonStructure([
            'data' => [['id', 'number', 'subject', 'status', 'priority', 'department' => ['id', 'name'], 'customer' => ['id', 'name', 'email'], 'assignee' => ['id', 'name'], 'created_at']],
            'meta' => ['total', 'per_page'],
        ]);
        $body = json_encode($response->json());
        $this->assertArrayNotHasKey('description', $response->json('data.0'));
        $this->assertStringNotContainsString('password', $body);
        $this->assertNotNull($admin);
    }

    public function test_listing_is_newest_first_and_paginated(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $customer = $this->makeUser(RoleName::Customer);
        $first = $this->ticketFor($customer, ['subject' => 'First']);
        $second = $this->ticketFor($customer, ['subject' => 'Second']);

        $this->getJson('/api/tickets')->assertOk()->assertJsonPath('data.0.id', $second->id)->assertJsonPath('data.1.id', $first->id);
        $this->getJson('/api/tickets?per_page=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2);
    }

    public function test_filters(): void
    {
        $admin = $this->actingAsRole(RoleName::Admin);
        $agent = $this->makeUser(RoleName::Agent);
        $customer = $this->makeUser(RoleName::Customer);
        $billing = Department::factory()->create();
        $this->ticketFor($customer, ['subject' => 'Invoice wrong', 'status' => TicketStatus::Open, 'priority' => TicketPriority::High, 'department_id' => $billing->id]);
        $this->ticketFor($customer, ['subject' => 'App crashes', 'status' => TicketStatus::Resolved, 'priority' => TicketPriority::Low, 'assigned_to' => $agent->id]);
        $this->ticketFor($customer, ['subject' => 'Other', 'status' => TicketStatus::Open, 'assigned_to' => $admin->id]);

        $this->getJson('/api/tickets?status=resolved')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.subject', 'App crashes');
        $this->getJson('/api/tickets?priority=high')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.subject', 'Invoice wrong');
        $this->getJson("/api/tickets?department_id={$billing->id}")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/tickets?assigned=me')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.subject', 'Other');
        $this->getJson('/api/tickets?assigned=unassigned')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.subject', 'Invoice wrong');
        $this->getJson('/api/tickets?search=crash')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/tickets?search=%25')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_searching_by_ticket_number(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $customer = $this->makeUser(RoleName::Customer);
        $target = $this->ticketFor($customer, ['subject' => 'Target']);
        $this->ticketFor($customer, ['subject' => 'Decoy']);

        foreach ([$target->number, strtolower($target->number), (string) $target->id] as $term) {
            $this->getJson('/api/tickets?search='.urlencode($term))->assertOk()->assertJsonPath('data.0.subject', 'Target');
        }
    }

    public function test_filters_never_widen_what_a_customer_can_see(): void
    {
        $me = $this->actingAsRole(RoleName::Customer);
        $this->ticketFor($me, ['subject' => 'Mine']);
        $theirs = $this->ticketFor($this->makeUser(RoleName::Customer), ['subject' => 'Theirs', 'priority' => TicketPriority::Urgent]);

        // Searching for, filtering to, or asking for the id of someone else's ticket reveals nothing.
        $this->getJson('/api/tickets?search=Theirs')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/tickets?search='.$theirs->number)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/tickets?priority=urgent')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/tickets?assigned=unassigned')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.subject', 'Mine');
    }

    public function test_invalid_filters_are_rejected(): void
    {
        $this->actingAsRole(RoleName::Admin);

        $this->getJson('/api/tickets?status=bogus')->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->getJson('/api/tickets?priority=bogus')->assertUnprocessable()->assertJsonValidationErrors('priority');
        $this->getJson('/api/tickets?assigned=everyone')->assertUnprocessable()->assertJsonValidationErrors('assigned');
        $this->getJson('/api/tickets?per_page=1000')->assertUnprocessable()->assertJsonValidationErrors('per_page');
    }

    // ---- Detail ---------------------------------------------------------------

    public function test_show_includes_the_description_and_people(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $customer = $this->makeUser(RoleName::Customer, ['name' => 'Cara Customer']);
        $ticket = $this->ticketFor($customer, ['subject' => 'S', 'description' => 'Full text of the problem']);

        $this->getJson("/api/tickets/{$ticket->id}")->assertOk()
            ->assertJsonPath('ticket.description', 'Full text of the problem')
            ->assertJsonPath('ticket.customer.name', 'Cara Customer')
            ->assertJsonPath('ticket.number', $ticket->number);
    }

    public function test_a_ticket_survives_its_department_being_deleted_and_its_customer_cannot_be_deleted(): void
    {
        $customer = $this->makeUser(RoleName::Customer);
        $department = Department::factory()->create();
        $ticket = $this->ticketFor($customer, ['department_id' => $department->id]);

        $department->delete();
        $this->assertNull($ticket->fresh()->department_id);

        $this->expectException(\Illuminate\Database\QueryException::class);
        $customer->delete();
    }

    public function test_an_inactive_user_gets_no_access(): void
    {
        $customer = $this->actingAsRole(RoleName::Customer);
        $customer->forceFill(['status' => \App\Enums\UserStatus::Inactive])->save();

        $this->getJson('/api/tickets')->assertForbidden()->assertJsonPath('code', 'account_inactive');
    }
}
