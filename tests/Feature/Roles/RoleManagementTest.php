<?php

namespace Tests\Feature\Roles;

use App\Enums\RoleName;
use App\Enums\TicketStatus;
use App\Models\AuditLog;
use App\Models\Ticket;
use App\Models\User;
use App\Support\PermissionCatalog;
use App\Support\Permissions;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class RoleManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $super;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->super = $this->user(RoleName::SuperAdmin);
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

    private function forgetCache(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** An admin who has been trusted with this screen (the default admin cannot even open it). */
    private function delegatedAdmin(bool $canEdit = true): User
    {
        $grant = $canEdit ? ['role.view', 'permission.assign'] : ['role.view'];
        Role::findByName('admin', 'web')->givePermissionTo($grant);
        $this->forgetCache();

        return $this->user(RoleName::Admin);
    }

    private function as(User $user): static
    {
        Sanctum::actingAs($user);

        return $this;
    }

    /** @return array<string, mixed> */
    private function role(string $name, ?User $as = null): array
    {
        $this->as($as ?? $this->super);

        return collect($this->getJson('/api/roles')->assertOk()->json('roles'))->firstWhere('name', $name);
    }

    /** @return list<string> */
    private function permissions(string $role): array
    {
        return Role::findByName($role, 'web')->permissions()->pluck('name')->sort()->values()->all();
    }

    private function save(string $role, array $permissions, ?string $version = null, ?User $as = null)
    {
        $version ??= $this->role($role, $as)['version'];
        $this->as($as ?? $this->super);

        return $this->putJson("/api/roles/{$role}/permissions", ['permissions' => $permissions, 'version' => $version]);
    }

    /** The role's current permissions plus `$add`, minus `$remove`. */
    private function with(string $role, array $add = [], array $remove = []): array
    {
        return array_values(array_diff(array_unique([...$this->permissions($role), ...$add]), $remove));
    }

    // ---- Access -------------------------------------------------------------

    public function test_only_people_holding_the_role_permissions_get_in(): void
    {
        $calls = [
            fn () => $this->getJson('/api/roles'),
            fn () => $this->getJson('/api/permissions'),
            fn () => $this->getJson('/api/roles/agent/history'),
        ];
        foreach ([RoleName::Customer, RoleName::Agent, RoleName::Admin] as $role) { // an ordinary admin has no role.view
            $this->as($this->user($role));
            foreach ($calls as $call) {
                $call()->assertForbidden();
            }
            $this->putJson('/api/roles/agent/permissions', ['permissions' => [], 'version' => 'x'])->assertForbidden();
            $this->postJson('/api/roles/agent/reset', ['version' => 'x'])->assertForbidden();
        }

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/roles')->assertUnauthorized();
        $this->as($this->super);
        foreach ($calls as $call) {
            $call()->assertOk();
        }
    }

    public function test_a_read_only_admin_can_look_but_not_change(): void
    {
        $viewer = $this->delegatedAdmin(canEdit: false);
        $this->as($viewer)->getJson('/api/roles')->assertOk();
        $this->getJson('/api/permissions')->assertOk();

        $this->save('agent', $this->with('agent', ['report.view']), as: $viewer)->assertForbidden();
        $roles = collect($this->getJson('/api/roles')->json('roles'));
        $this->assertTrue($roles->every(fn ($r) => $r['editable'] === false));
        $this->assertSame('You do not have permission to change roles.', $roles->firstWhere('name', 'agent')['locked_reason']);
    }

    public function test_only_the_four_built_in_roles_exist_here(): void
    {
        $this->as($this->super);
        $this->getJson('/api/roles/nonsense/history')->assertNotFound();
        $this->getJson('/api/roles/admin%3B%20DROP/history')->assertNotFound();
        $this->putJson('/api/roles/nonsense/permissions', ['permissions' => [], 'version' => 'x'])->assertNotFound();
    }

    // ---- Reading ------------------------------------------------------------

    public function test_the_role_list_describes_each_role_with_its_people_and_state(): void
    {
        $this->user(RoleName::Agent);
        $this->user(RoleName::Agent);
        $this->user(RoleName::Customer);

        $roles = collect($this->as($this->super)->getJson('/api/roles')->assertOk()->json('roles'))->keyBy('name');

        $this->assertSame(['super-admin', 'admin', 'agent', 'customer'], $roles->keys()->all());
        $this->assertSame(2, $roles['agent']['user_count']);
        $this->assertSame(1, $roles['customer']['user_count']);
        $this->assertEqualsCanonicalizing(PermissionCatalog::defaults(RoleName::Agent), $roles['agent']['permissions']);
        $this->assertTrue($roles['agent']['is_default']);
        $this->assertSame(12, strlen($roles['agent']['version']));
        $this->assertEqualsCanonicalizing(PermissionCatalog::defaults(RoleName::Agent), $roles['agent']['default_permissions']);
        // Super Admin shows everything, locked.
        $this->assertCount(count(Permissions::ALL), $roles['super-admin']['permissions']);
        $this->assertFalse($roles['super-admin']['editable']);
        $this->assertStringContainsString('cannot be changed', $roles['super-admin']['locked_reason']);
        $this->assertTrue($roles['admin']['editable'] && $roles['agent']['editable'] && $roles['customer']['editable']);
    }

    public function test_a_delegated_admin_may_change_only_the_roles_below(): void
    {
        $roles = collect($this->as($this->delegatedAdmin())->getJson('/api/roles')->json('roles'))->keyBy('name');

        $this->assertFalse($roles['super-admin']['editable']);
        $this->assertFalse($roles['admin']['editable']);
        $this->assertSame('You cannot change your own role or a higher one.', $roles['admin']['locked_reason']);
        $this->assertTrue($roles['agent']['editable']);
        $this->assertTrue($roles['customer']['editable']);
    }

    public function test_the_catalogue_covers_every_permission_and_says_who_may_hold_it(): void
    {
        $groups = $this->as($this->super)->getJson('/api/permissions')->assertOk()->json('groups');
        $all = collect($groups)->flatMap(fn ($g) => $g['permissions'])->keyBy('name');

        $this->assertCount(count(Permissions::ALL), $all);
        $this->assertSame(['admin'], $all['user.view']['allowed_for']);
        $this->assertSame(['admin', 'agent'], $all['ticket.view_all']['allowed_for']);
        $this->assertSame(['admin', 'agent', 'customer'], $all['ticket.view']['allowed_for']);
        $this->assertSame(['customer'], array_values(array_intersect(['customer'], $all['ticket.create']['required_for'])));
        $this->assertTrue($all['settings.manage']['reserved']);
        $this->assertSame([], $all['settings.manage']['allowed_for']);
        $this->assertTrue($all['ticket.view_all']['sensitive']);
        $this->assertTrue($all['ticket.view_all']['can_grant']);
    }

    public function test_the_catalogue_tells_a_delegated_admin_what_they_cannot_hand_out(): void
    {
        $all = collect($this->as($this->delegatedAdmin())->getJson('/api/permissions')->json('groups'))->flatMap(fn ($g) => $g['permissions'])->keyBy('name');

        $this->assertTrue($all['report.view']['can_grant']);     // the admin role holds it
        $this->assertFalse($all['activity.view']['can_grant']);  // the admin role does not
    }

    // ---- Changing -----------------------------------------------------------

    public function test_a_change_is_saved_audited_and_takes_effect_on_the_next_request(): void
    {
        $agent = $this->user(RoleName::Agent);
        $this->as($agent)->getJson('/api/reports/overview')->assertForbidden();

        $response = $this->save('agent', $this->with('agent', ['report.view']))->assertOk();

        $response->assertJsonPath('added', ['report.view'])->assertJsonPath('removed', [])->assertJsonPath('message', 'Permissions updated.');
        $this->assertContains('report.view', $this->permissions('agent'));
        $this->assertSame($this->role('agent')['version'], $response->json('role.version')); // the answer carries the new version
        // No new sign-in needed: the same person, the very next request.
        $this->as($agent)->getJson('/api/reports/overview')->assertOk();

        $log = AuditLog::where('action', 'role.permissions_updated')->firstOrFail();
        $this->assertSame($this->super->id, $log->actor_id);
        $this->assertSame('Role', $log->subject_type);
        $this->assertSame(['role' => 'agent', 'added' => ['report.view'], 'removed' => []], $log->metadata);
        $this->assertNotNull($log->ip_address);
    }

    public function test_taking_a_permission_away_also_takes_effect_at_once(): void
    {
        $customer = User::factory()->create()->assignRole('customer');
        $ticket = Ticket::factory()->create(['customer_id' => $customer->id, 'status' => TicketStatus::Resolved, 'resolved_at' => now()]);
        $this->as($customer)->postJson("/api/tickets/{$ticket->id}/status", ['status' => 'closed'])->assertOk();
        $another = Ticket::factory()->create(['customer_id' => $customer->id, 'status' => TicketStatus::Resolved, 'resolved_at' => now()]);

        $this->save('customer', $this->with('customer', [], ['ticket.close']))->assertOk()->assertJsonPath('removed', ['ticket.close']);

        $this->as($customer)->postJson("/api/tickets/{$another->id}/status", ['status' => 'closed'])->assertForbidden();
    }

    public function test_the_new_version_lets_the_next_change_through_and_the_old_one_does_not(): void
    {
        $first = $this->role('agent')['version'];

        $saved = $this->save('agent', $this->with('agent', ['report.view']), $first)->assertOk();
        $next = $saved->json('role.version');
        $this->assertNotSame($first, $next);

        $this->save('agent', $this->with('agent', [], ['report.view']), $first)->assertStatus(409)->assertJsonPath('code', 'stale_version');
        $this->save('agent', $this->with('agent', [], ['report.view']), $next)->assertOk();
    }

    public function test_a_stale_save_changes_nothing(): void
    {
        $seen = $this->role('agent')['version'];
        $this->save('agent', $this->with('agent', ['report.view']), $seen)->assertOk(); // someone else saved first

        $before = $this->permissions('agent');
        $this->save('agent', $this->with('agent', ['activity.view'], ['ticket.reply']), $seen)->assertStatus(409);

        $this->assertSame($before, $this->permissions('agent'));
        $this->assertSame(1, AuditLog::where('action', 'role.permissions_updated')->count());
    }

    public function test_saving_the_same_permissions_changes_and_records_nothing(): void
    {
        $this->save('agent', $this->permissions('agent'))->assertOk()->assertJsonPath('message', 'Nothing changed.')->assertJsonPath('added', [])->assertJsonPath('removed', []);

        $this->assertSame(0, AuditLog::where('action', 'role.permissions_updated')->count());
    }

    public function test_the_order_and_duplicates_in_the_request_do_not_matter(): void
    {
        $wanted = array_reverse([...$this->permissions('agent'), 'report.view', 'report.view']);

        $this->save('agent', $wanted)->assertOk()->assertJsonPath('added', ['report.view']);
        $this->assertSame(count(array_unique($wanted)), count($this->permissions('agent')));
    }

    public function test_a_missing_or_malformed_body_is_refused(): void
    {
        $this->as($this->super);
        $this->putJson('/api/roles/agent/permissions', ['version' => $this->role('agent')['version']])->assertUnprocessable()->assertJsonValidationErrors('permissions');
        $this->putJson('/api/roles/agent/permissions', ['permissions' => $this->permissions('agent')])->assertUnprocessable()->assertJsonValidationErrors('version');
        $this->putJson('/api/roles/agent/permissions', ['permissions' => 'report.view', 'version' => 'x'])->assertUnprocessable();
        $this->putJson('/api/roles/agent/permissions', ['permissions' => [['report.view']], 'version' => 'x'])->assertUnprocessable();
    }

    // ---- The rules ----------------------------------------------------------

    public function test_nobody_can_change_the_super_admin_role(): void
    {
        foreach ([$this->super, $this->delegatedAdmin()] as $editor) {
            $this->save('super-admin', [], 'anything', $editor)->assertForbidden()->assertJsonPath('code', 'role_locked');
            $this->postJson('/api/roles/super-admin/reset', ['version' => 'x'])->assertForbidden();
        }
        $this->assertSame([], $this->permissions('super-admin'));
    }

    public function test_the_customer_can_never_be_given_staff_permissions(): void
    {
        foreach (['ticket.view_all', 'ticket.internal_note', 'ticket.assign', 'ticket.update_status', 'user.view', 'report.view', 'activity.view', 'role.view', 'permission.assign', 'department.view'] as $name) {
            $this->save('customer', $this->with('customer', [$name]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('permissions');
            $this->assertNotContains($name, $this->permissions('customer'), "the customer got {$name}");
        }
    }

    public function test_the_agent_can_never_be_given_the_permissions_that_run_the_helpdesk(): void
    {
        foreach (['user.view', 'user.create', 'user.assign_role', 'role.view', 'permission.assign', 'department.create', 'activity.view'] as $name) {
            $this->save('agent', $this->with('agent', [$name]))->assertUnprocessable();
            $this->assertNotContains($name, $this->permissions('agent'));
        }
    }

    public function test_the_admin_role_cannot_receive_permissions_reserved_for_the_system(): void
    {
        foreach (['settings.manage', 'role.create', 'role.update', 'role.delete', 'permission.view', 'user.delete', 'ticket.update'] as $name) {
            $response = $this->save('admin', $this->with('admin', [$name]))->assertUnprocessable();
            $this->assertStringContainsString('not used by any feature yet', $response->json('errors.permissions.0'));
        }
    }

    public function test_unknown_permissions_are_refused(): void
    {
        $this->save('agent', $this->with('agent', ['ticket.delete_everything']))->assertUnprocessable();
        $this->save('agent', $this->with('agent', ['*']))->assertUnprocessable();
        $this->save('agent', $this->with('agent', ['../etc/passwd']))->assertUnprocessable();
        $this->assertEqualsCanonicalizing(PermissionCatalog::defaults(RoleName::Agent), $this->permissions('agent'));
    }

    public function test_a_role_cannot_lose_what_it_needs_to_work(): void
    {
        $this->save('customer', $this->with('customer', [], ['ticket.create']))->assertUnprocessable();
        $this->save('customer', $this->with('customer', [], ['ticket.view']))->assertUnprocessable();
        $this->save('customer', [])->assertUnprocessable();
        $this->save('agent', $this->with('agent', [], ['ticket.view']))->assertUnprocessable();
        $this->save('admin', $this->with('admin', [], ['user.view']))->assertUnprocessable();
        $this->save('admin', $this->with('admin', [], ['ticket.view_all']))->assertUnprocessable();

        $this->assertContains('ticket.create', $this->permissions('customer'));
        $this->assertContains('user.view', $this->permissions('admin'));
    }

    public function test_the_error_explains_each_problem(): void
    {
        $response = $this->save('customer', ['ticket.view', 'ticket.view_all', 'nonsense'])->assertUnprocessable();

        $messages = implode("\n", $response->json('errors.permissions'));
        $this->assertStringContainsString('"View every ticket" (ticket.view_all) can never be given to the Customer role', $messages);
        $this->assertStringContainsString('"nonsense" is not a permission this system knows', $messages);
        $this->assertStringContainsString('"Open tickets" (ticket.create) is required', $messages);
    }

    public function test_a_refused_change_leaves_everything_as_it_was(): void
    {
        $before = $this->permissions('customer');

        $this->save('customer', [...$before, 'ticket.view_all'])->assertUnprocessable();

        $this->assertSame($before, $this->permissions('customer'));
        $this->assertSame(0, AuditLog::count());
    }

    // ---- Climbing -----------------------------------------------------------

    public function test_a_delegated_admin_cannot_change_their_own_role(): void
    {
        $admin = $this->delegatedAdmin();

        $this->save('admin', $this->with('admin', ['activity.view']), as: $admin)->assertForbidden()->assertJsonPath('code', 'role_locked');
        $this->assertNotContains('activity.view', $this->permissions('admin'));
    }

    public function test_a_delegated_admin_cannot_hand_out_what_they_do_not_hold(): void
    {
        $admin = $this->delegatedAdmin();
        $this->assertFalse($admin->can('activity.view'));

        // The agent role's ceiling would allow report.view, but not activity.view; use a permission both allow and the admin lacks.
        Role::findByName('admin', 'web')->revokePermissionTo('report.view');
        $this->forgetCache();
        $response = $this->save('agent', $this->with('agent', ['report.view']), as: $admin->fresh())->assertUnprocessable();

        $this->assertStringContainsString('you do not have it yourself', $response->json('errors.permissions.0'));
        $this->assertNotContains('report.view', $this->permissions('agent'));
    }

    public function test_a_delegated_admin_can_hand_out_what_they_hold(): void
    {
        $admin = $this->delegatedAdmin();
        $this->assertTrue($admin->can('report.view'));

        $this->save('agent', $this->with('agent', ['report.view']), as: $admin)->assertOk();

        $this->assertContains('report.view', $this->permissions('agent'));
        $this->assertSame($admin->id, AuditLog::where('action', 'role.permissions_updated')->firstOrFail()->actor_id);
    }

    public function test_a_delegated_admin_can_still_take_away_what_they_lack(): void
    {
        $admin = $this->delegatedAdmin();
        Role::findByName('agent', 'web')->givePermissionTo('report.view');
        Role::findByName('admin', 'web')->revokePermissionTo('report.view');
        $this->forgetCache();

        $this->save('agent', $this->with('agent', [], ['report.view']), as: $admin->fresh())->assertOk()->assertJsonPath('removed', ['report.view']);
    }

    public function test_the_admin_role_cannot_be_used_to_reach_the_role_screen_through_a_lower_role(): void
    {
        $admin = $this->delegatedAdmin();

        // Agents may never hold the permissions that manage roles, so no edit can put them there.
        $this->save('agent', $this->with('agent', ['permission.assign', 'role.view']), as: $admin)->assertUnprocessable();
        $this->save('customer', $this->with('customer', ['permission.assign']), as: $admin)->assertUnprocessable();
    }

    // ---- Reset --------------------------------------------------------------

    public function test_reset_returns_a_role_to_what_it_ships_with(): void
    {
        $this->save('agent', $this->with('agent', ['report.view'], ['ticket.reply']))->assertOk();
        $this->assertFalse($this->role('agent')['is_default']);

        $response = $this->postJson('/api/roles/agent/reset', ['version' => $this->role('agent')['version']])->assertOk();

        $response->assertJsonPath('added', ['ticket.reply'])->assertJsonPath('removed', ['report.view'])->assertJsonPath('role.is_default', true);
        $this->assertEqualsCanonicalizing(PermissionCatalog::defaults(RoleName::Agent), $this->permissions('agent'));
        $this->assertSame(1, AuditLog::where('action', 'role.permissions_reset')->count());
    }

    public function test_reset_of_a_role_already_at_the_defaults_does_nothing(): void
    {
        $this->as($this->super)->postJson('/api/roles/agent/reset', ['version' => $this->role('agent')['version']])
            ->assertOk()->assertJsonPath('message', 'Already at the defaults.');

        $this->assertSame(0, AuditLog::count());
    }

    public function test_reset_is_also_checked_for_a_stale_version_and_follows_the_same_rules(): void
    {
        $seen = $this->role('agent')['version'];
        $this->save('agent', $this->with('agent', ['report.view']))->assertOk();
        $this->postJson('/api/roles/agent/reset', ['version' => $seen])->assertStatus(409);

        $this->postJson('/api/roles/agent/reset')->assertUnprocessable();
        $this->postJson('/api/roles/super-admin/reset', ['version' => 'x'])->assertForbidden();
    }

    // ---- History ------------------------------------------------------------

    public function test_history_lists_changes_newest_first_with_who_and_what(): void
    {
        $this->save('agent', $this->with('agent', ['report.view']))->assertOk();
        $this->save('agent', $this->with('agent', [], ['report.view', 'ticket.reply']))->assertOk();
        $this->save('customer', $this->with('customer', [], ['ticket.close']))->assertOk(); // a different role

        $history = $this->getJson('/api/roles/agent/history')->assertOk()->json('data');

        $this->assertCount(2, $history);
        $this->assertSame(['report.view', 'ticket.reply'], $history[0]['removed']);
        $this->assertSame(['report.view'], $history[1]['added']);
        $this->assertSame($this->super->name, $history[0]['actor']['name']);
        $this->assertSame('updated', $history[0]['action']);
        $this->assertArrayHasKey('created_at', $history[0]);
    }

    public function test_history_survives_the_removal_of_the_person_who_made_the_change(): void
    {
        $editor = $this->user(RoleName::SuperAdmin);
        $this->save('agent', $this->with('agent', ['report.view']), as: $editor)->assertOk();
        $editor->tokens()->delete();
        $editor->delete();

        $history = $this->as($this->super)->getJson('/api/roles/agent/history')->json('data');
        $this->assertNull($history[0]['actor']);
    }

    // ---- Safety of the write ------------------------------------------------

    public function test_the_permissions_and_their_audit_entry_succeed_or_fail_together(): void
    {
        AuditLog::creating(fn () => throw new \RuntimeException('audit table is unavailable'));
        $before = $this->permissions('agent');

        $this->save('agent', $this->with('agent', ['report.view']))->assertStatus(500);

        $this->assertSame($before, $this->permissions('agent'));
    }

    public function test_nothing_in_the_change_touches_the_users_or_their_roles(): void
    {
        $agent = $this->user(RoleName::Agent);

        $this->save('agent', $this->with('agent', ['report.view']))->assertOk();

        $this->assertTrue($agent->fresh()->hasRole('agent'));
        $this->assertSame(1, $this->role('agent')['user_count']);
    }
}
