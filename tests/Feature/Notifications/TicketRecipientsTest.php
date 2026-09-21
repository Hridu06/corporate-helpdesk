<?php

namespace Tests\Feature\Notifications;

use App\Enums\RoleName;
use App\Enums\TicketActivity;
use App\Enums\UserStatus;
use App\Models\Department;
use App\Models\Ticket;
use App\Models\User;
use App\Support\Notifications\TicketRecipients;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class TicketRecipientsTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    private User $agent;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->customer = $this->user(RoleName::Customer);
        $this->agent = $this->user(RoleName::Agent);
        $this->admin = $this->user(RoleName::Admin);
    }

    private function user(RoleName $role, array $attributes = []): User
    {
        return User::factory()->create($attributes)->assignRole($role->value);
    }

    private function ticket(array $attributes = []): Ticket
    {
        return Ticket::factory()->create(array_merge([
            'customer_id' => $this->customer->id,
            'assigned_to' => $this->agent->id,
        ], $attributes));
    }

    /** @return list<int> */
    private function ids(TicketActivity $activity, Ticket $ticket, User $actor): array
    {
        return TicketRecipients::for($activity, $ticket, $actor)->pluck('id')->sort()->values()->all();
    }

    private function department(): Department
    {
        return Department::create(['name' => 'Billing', 'description' => 'x', 'is_active' => true]);
    }

    // ---- Ticket opened ------------------------------------------------------

    public function test_the_customer_gets_a_confirmation_even_though_they_are_the_actor(): void
    {
        $ticket = $this->ticket(['assigned_to' => null]);

        $this->assertSame([$this->customer->id], $this->ids(TicketActivity::Opened, $ticket, $this->customer));
    }

    public function test_admins_who_can_assign_hear_about_a_ticket_with_no_department(): void
    {
        $ticket = $this->ticket(['assigned_to' => null]);
        $secondAdmin = $this->user(RoleName::Admin);

        $this->assertSame(
            collect([$this->admin->id, $secondAdmin->id])->sort()->values()->all(),
            $this->ids(TicketActivity::NewForStaff, $ticket, $this->customer),
        );
    }

    public function test_agents_customers_and_super_admins_are_not_in_that_list(): void
    {
        $ticket = $this->ticket(['assigned_to' => null]);
        $this->user(RoleName::SuperAdmin); // holds everything implicitly, not as stored permissions

        $ids = $this->ids(TicketActivity::NewForStaff, $ticket, $this->customer);

        $this->assertSame([$this->admin->id], $ids);
    }

    public function test_a_ticket_with_a_department_sends_no_new_ticket_email_to_admins(): void
    {
        $ticket = $this->ticket(['assigned_to' => null, 'department_id' => $this->department()->id]);

        $this->assertSame([], $this->ids(TicketActivity::NewForStaff, $ticket, $this->customer));
        // The customer's confirmation is unaffected.
        $this->assertSame([$this->customer->id], $this->ids(TicketActivity::Opened, $ticket, $this->customer));
    }

    public function test_the_list_follows_the_permission_not_the_role_name(): void
    {
        $ticket = $this->ticket(['assigned_to' => null]);
        $this->admin->roles->first()->revokePermissionTo('ticket.assign');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $agentWithAssign = $this->user(RoleName::Agent);
        $agentWithAssign->givePermissionTo('ticket.assign', 'ticket.view_all');

        $this->assertSame([$agentWithAssign->id], $this->ids(TicketActivity::NewForStaff, $ticket, $this->customer));
    }

    public function test_someone_who_may_assign_but_not_view_the_ticket_is_not_emailed(): void
    {
        $ticket = $this->ticket(['assigned_to' => null]);
        $assignOnly = $this->user(RoleName::Agent);
        $assignOnly->givePermissionTo('ticket.assign'); // not assigned to it, no view_all

        $this->assertNotContains($assignOnly->id, $this->ids(TicketActivity::NewForStaff, $ticket, $this->customer));
    }

    // ---- Assignment ---------------------------------------------------------

    public function test_only_the_new_assignee_hears_about_an_assignment(): void
    {
        $ticket = $this->ticket();

        $this->assertSame([$this->agent->id], $this->ids(TicketActivity::Assigned, $ticket, $this->admin));
        $this->assertSame([], $this->ids(TicketActivity::Assigned, $ticket, $this->agent)); // self
        $this->assertSame([], $this->ids(TicketActivity::Assigned, $this->ticket(['assigned_to' => null]), $this->admin));
    }

    // ---- Replies ------------------------------------------------------------

    public function test_a_staff_reply_goes_to_the_customer_only(): void
    {
        $this->assertSame([$this->customer->id], $this->ids(TicketActivity::StaffReply, $this->ticket(), $this->agent));
    }

    public function test_a_customer_reply_goes_to_the_assignee_or_else_the_assigners(): void
    {
        $this->assertSame([$this->agent->id], $this->ids(TicketActivity::CustomerReply, $this->ticket(), $this->customer));
        $this->assertSame([$this->admin->id], $this->ids(TicketActivity::CustomerReply, $this->ticket(['assigned_to' => null]), $this->customer));
    }

    // ---- Internal notes -----------------------------------------------------

    public function test_an_internal_note_goes_to_the_assignee_unless_they_wrote_it(): void
    {
        $ticket = $this->ticket();

        $this->assertSame([$this->agent->id], $this->ids(TicketActivity::InternalNote, $ticket, $this->admin));
        $this->assertSame([], $this->ids(TicketActivity::InternalNote, $ticket, $this->agent));
        $this->assertSame([], $this->ids(TicketActivity::InternalNote, $this->ticket(['assigned_to' => null]), $this->admin));
    }

    public function test_an_internal_note_never_reaches_the_customer_even_in_odd_setups(): void
    {
        // The customer somehow is the assignee, and holds every relevant permission.
        $this->customer->givePermissionTo('ticket.internal_note', 'ticket.view_all');
        $ticket = $this->ticket(['assigned_to' => $this->customer->id]);

        $this->assertSame([], $this->ids(TicketActivity::InternalNote, $ticket, $this->admin));
        $this->assertFalse(TicketRecipients::mayReceive(TicketActivity::InternalNote, $ticket, $this->customer));

        // ...and no other activity sends the customer a staff-audience email.
        foreach ([TicketActivity::NewForStaff, TicketActivity::Assigned, TicketActivity::CustomerReply, TicketActivity::CustomerClosed, TicketActivity::CustomerReopened] as $activity) {
            $ids = $this->ids($activity, $this->ticket(['assigned_to' => $this->customer->id]), $this->admin);
            $this->assertNotContains($this->customer->id, $ids, "{$activity->value} must not reach the customer");
        }
    }

    public function test_an_assignee_who_lost_the_note_permission_is_not_emailed(): void
    {
        $ticket = $this->ticket();
        $this->agent->roles->first()->revokePermissionTo('ticket.internal_note');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertSame([], $this->ids(TicketActivity::InternalNote, $ticket, $this->admin));
    }

    // ---- Status changes -----------------------------------------------------

    public function test_staff_status_changes_notify_the_customer(): void
    {
        foreach ([TicketActivity::Resolved, TicketActivity::Closed, TicketActivity::Reopened] as $activity) {
            $this->assertSame([$this->customer->id], $this->ids($activity, $this->ticket(), $this->agent), $activity->value);
        }
    }

    public function test_customer_close_and_reopen_notify_the_assignee(): void
    {
        foreach ([TicketActivity::CustomerClosed, TicketActivity::CustomerReopened] as $activity) {
            $this->assertSame([$this->agent->id], $this->ids($activity, $this->ticket(), $this->customer), $activity->value);
        }
    }

    // ---- Filters ------------------------------------------------------------

    public function test_inactive_and_unverified_people_are_never_emailed(): void
    {
        $ticket = $this->ticket();

        $this->agent->forceFill(['status' => UserStatus::Inactive])->save();
        $this->assertSame([], $this->ids(TicketActivity::Assigned, $ticket->fresh(), $this->admin));

        $this->agent->forceFill(['status' => UserStatus::Active, 'email_verified_at' => null])->save();
        $this->assertSame([], $this->ids(TicketActivity::Assigned, $ticket->fresh(), $this->admin));

        $this->customer->forceFill(['email_verified_at' => null])->save();
        $this->assertSame([], $this->ids(TicketActivity::StaffReply, $ticket->fresh(), $this->agent));
        $this->assertSame([], $this->ids(TicketActivity::Opened, $ticket->fresh(), $this->customer));
    }

    public function test_the_actor_is_never_emailed_about_their_own_action(): void
    {
        $ticket = $this->ticket(['assigned_to' => null]);

        $this->assertSame([], $this->ids(TicketActivity::NewForStaff, $ticket, $this->admin));
        $this->assertSame([], $this->ids(TicketActivity::CustomerReply, $ticket, $this->admin));
    }

    public function test_nobody_is_listed_twice(): void
    {
        $ticket = $this->ticket(['assigned_to' => null]);
        // Holds the permission through the role AND directly.
        $this->admin->givePermissionTo('ticket.assign');

        $this->assertSame([$this->admin->id], $this->ids(TicketActivity::NewForStaff, $ticket, $this->customer));
    }

    public function test_a_customer_email_only_ever_goes_to_that_ticket_s_customer(): void
    {
        $other = $this->user(RoleName::Customer);
        $ticket = $this->ticket();

        $this->assertFalse(TicketRecipients::mayReceive(TicketActivity::StaffReply, $ticket, $other));
        $this->assertFalse(TicketRecipients::mayReceive(TicketActivity::StaffReply, $ticket, $this->agent));
        $this->assertTrue(TicketRecipients::mayReceive(TicketActivity::StaffReply, $ticket, $this->customer));
    }

    public function test_an_unassigned_agent_may_not_receive_staff_emails_about_the_ticket(): void
    {
        $otherAgent = $this->user(RoleName::Agent);

        $this->assertFalse(TicketRecipients::mayReceive(TicketActivity::CustomerReply, $this->ticket(), $otherAgent));
    }
}
