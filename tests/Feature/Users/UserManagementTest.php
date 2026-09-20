<?php

namespace Tests\Feature\Users;

use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserManagementTest extends TestCase
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

    private function actingAsRole(RoleName $role, array $extraPermissions = []): User
    {
        $user = $this->makeUser($role);
        $user->givePermissionTo($extraPermissions);
        Sanctum::actingAs($user);

        return $user;
    }

    // ---- Authentication / basic authorization -------------------------------

    public function test_endpoints_require_authentication(): void
    {
        $target = $this->makeUser(RoleName::Customer);

        $this->getJson('/api/users')->assertUnauthorized();
        $this->postJson('/api/users', [])->assertUnauthorized();
        $this->getJson("/api/users/{$target->id}")->assertUnauthorized();
        $this->patchJson("/api/users/{$target->id}", [])->assertUnauthorized();
        $this->postJson("/api/users/{$target->id}/deactivate")->assertUnauthorized();
        $this->putJson("/api/users/{$target->id}/role", [])->assertUnauthorized();
    }

    public function test_customer_and_agent_cannot_use_user_management(): void
    {
        $target = $this->makeUser(RoleName::Customer);

        foreach ([RoleName::Customer, RoleName::Agent] as $role) {
            $this->actingAsRole($role);
            $this->getJson('/api/users')->assertForbidden();
            $this->getJson("/api/users/{$target->id}")->assertForbidden();
            $this->postJson('/api/users', ['name' => 'X', 'email' => 'x@example.com', 'role' => 'customer'])->assertForbidden();
            $this->patchJson("/api/users/{$target->id}", ['name' => 'Hacked'])->assertForbidden();
            $this->postJson("/api/users/{$target->id}/deactivate")->assertForbidden();
            $this->putJson("/api/users/{$target->id}/role", ['role' => 'admin'])->assertForbidden();
        }

        $this->assertSame('customer', $target->fresh()->getRoleNames()->first());
        $this->assertTrue($target->fresh()->isActive());
    }

    public function test_deactivated_admin_is_locked_out(): void
    {
        $admin = $this->actingAsRole(RoleName::Admin);
        $admin->forceFill(['status' => UserStatus::Inactive])->save();

        $this->getJson('/api/users')->assertForbidden()->assertJsonPath('code', 'account_inactive');
    }

    // ---- Listing ------------------------------------------------------------

    public function test_admin_can_list_users_without_sensitive_fields(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $this->makeUser(RoleName::Customer);

        $response = $this->getJson('/api/users')->assertOk();

        $response->assertJsonStructure([
            'data' => [['id', 'name', 'email', 'status', 'roles', 'email_verified_at', 'last_login_at', 'created_at']],
            'meta' => ['current_page', 'per_page', 'total'],
        ]);
        $body = json_encode($response->json());
        $this->assertStringNotContainsString('password', $body);
        $this->assertStringNotContainsString('remember_token', $body);
        $this->assertStringNotContainsString('last_login_ip', $body);
    }

    public function test_listing_supports_search_status_role_filters_and_pagination(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $this->makeUser(RoleName::Customer, ['name' => 'Alice Wonder', 'email' => 'alice@example.com']);
        $this->makeUser(RoleName::Customer, ['name' => 'Bob Builder', 'email' => 'bob@example.com', 'status' => UserStatus::Inactive]);
        $this->makeUser(RoleName::Agent, ['name' => 'Carol Agent', 'email' => 'carol@example.com']);

        $this->getJson('/api/users?search=alice')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.email', 'alice@example.com');
        $this->getJson('/api/users?status=inactive')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.email', 'bob@example.com');
        $this->getJson('/api/users?role=agent')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.email', 'carol@example.com');
        $this->getJson('/api/users?per_page=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.per_page', 2);
        $this->getJson('/api/users?status=bogus')->assertUnprocessable();
        $this->getJson('/api/users?per_page=1000')->assertUnprocessable();
    }

    public function test_search_treats_wildcards_literally(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $this->makeUser(RoleName::Customer, ['name' => 'Plain Person', 'email' => 'plain@example.com']);

        $this->getJson('/api/users?search=%25')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_show_returns_user_or_404(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $target = $this->makeUser(RoleName::Customer);

        $this->getJson("/api/users/{$target->id}")->assertOk()->assertJsonPath('user.email', $target->email);
        $this->getJson('/api/users/999999')->assertNotFound();
    }

    public function test_assignable_roles_depend_on_the_actor(): void
    {
        $this->actingAsRole(RoleName::Customer);
        $this->getJson('/api/users/assignable-roles')->assertForbidden();

        $this->actingAsRole(RoleName::Admin);
        $this->getJson('/api/users/assignable-roles')->assertOk()->assertExactJson(['roles' => ['agent', 'customer']]);

        $this->actingAsRole(RoleName::SuperAdmin);
        $roles = $this->getJson('/api/users/assignable-roles')->assertOk()->json('roles');
        $this->assertEqualsCanonicalizing(['super-admin', 'admin', 'agent', 'customer'], $roles);
    }

    // ---- Creation -----------------------------------------------------------

    public function test_admin_can_create_agent_and_customer_accounts(): void
    {
        Notification::fake();
        $admin = $this->actingAsRole(RoleName::Admin);

        $this->postJson('/api/users', ['name' => 'New Agent', 'email' => 'NewAgent@Example.com', 'role' => 'agent'])
            ->assertCreated()
            ->assertJsonPath('user.roles', ['agent'])
            ->assertJsonPath('user.status', 'active');

        $user = User::where('email', 'newagent@example.com')->firstOrFail();
        $this->assertNotNull($user->email_verified_at);
        Notification::assertSentTo($user, ResetPassword::class);
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $admin->id, 'action' => 'user.created', 'subject_id' => $user->id]);
    }

    public function test_created_account_has_no_usable_password_until_reset(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $this->postJson('/api/users', ['name' => 'Fresh', 'email' => 'fresh@example.com', 'role' => 'customer'])->assertCreated();

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/login', ['email' => 'fresh@example.com', 'password' => 'password'])->assertUnauthorized();
    }

    public function test_admin_cannot_create_privileged_accounts(): void
    {
        $this->actingAsRole(RoleName::Admin);

        foreach (['super-admin', 'admin'] as $role) {
            $this->postJson('/api/users', ['name' => 'Evil', 'email' => "{$role}@example.com", 'role' => $role])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('role');
        }

        $this->assertDatabaseCount('users', 1);
    }

    public function test_super_admin_can_create_admin_accounts(): void
    {
        $this->actingAsRole(RoleName::SuperAdmin);

        $this->postJson('/api/users', ['name' => 'Boss', 'email' => 'boss@example.com', 'role' => 'admin'])
            ->assertCreated()
            ->assertJsonPath('user.roles', ['admin']);
    }

    public function test_create_validates_input_and_ignores_extra_fields(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $this->makeUser(RoleName::Customer, ['email' => 'taken@example.com']);

        $this->postJson('/api/users', [])->assertUnprocessable()->assertJsonValidationErrors(['name', 'email', 'role']);
        $this->postJson('/api/users', ['name' => 'Dup', 'email' => 'taken@example.com', 'role' => 'agent'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->postJson('/api/users', ['name' => 'X', 'email' => 'x@example.com', 'role' => 'nonexistent'])
            ->assertUnprocessable()->assertJsonValidationErrors('role');

        $this->postJson('/api/users', [
            'name' => 'Sneaky', 'email' => 'sneaky@example.com', 'role' => 'customer',
            'password' => 'Chosen!Passw0rd1', 'status' => 'inactive', 'is_admin' => true,
        ])->assertCreated()->assertJsonPath('user.status', 'active');

        $this->assertFalse(password_verify('Chosen!Passw0rd1', User::where('email', 'sneaky@example.com')->first()->password));
    }

    // ---- Update -------------------------------------------------------------

    public function test_admin_can_update_a_users_name(): void
    {
        $admin = $this->actingAsRole(RoleName::Admin);
        $target = $this->makeUser(RoleName::Customer);

        $this->patchJson("/api/users/{$target->id}", ['name' => 'Renamed'])->assertOk()->assertJsonPath('user.name', 'Renamed');

        $this->assertDatabaseHas('audit_logs', ['actor_id' => $admin->id, 'action' => 'user.updated', 'subject_id' => $target->id]);
    }

    public function test_changing_email_requires_reverification_and_revokes_tokens(): void
    {
        Notification::fake();
        $this->actingAsRole(RoleName::Admin);
        $target = $this->makeUser(RoleName::Customer);
        $target->createToken('t');

        $this->patchJson("/api/users/{$target->id}", ['email' => 'Changed@Example.com'])->assertOk();

        $target->refresh();
        $this->assertSame('changed@example.com', $target->email);
        $this->assertNull($target->email_verified_at);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        Notification::assertSentTo($target, VerifyEmail::class);
    }

    public function test_update_cannot_smuggle_status_or_role(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $target = $this->makeUser(RoleName::Customer);

        $this->patchJson("/api/users/{$target->id}", ['name' => 'Ok', 'status' => 'inactive', 'role' => 'admin', 'password' => 'Hacked!Passw0rd1'])
            ->assertOk();

        $target->refresh();
        $this->assertTrue($target->isActive());
        $this->assertSame(['customer'], $target->getRoleNames()->all());
        $this->assertFalse(password_verify('Hacked!Passw0rd1', $target->password));
    }

    public function test_only_super_admin_can_modify_a_super_admin(): void
    {
        $super = $this->makeUser(RoleName::SuperAdmin);
        $admin = $this->actingAsRole(RoleName::Admin, ['user.deactivate', 'user.activate', 'user.assign_role']);

        $this->patchJson("/api/users/{$super->id}", ['name' => 'Pwned'])->assertForbidden();
        $this->postJson("/api/users/{$super->id}/deactivate")->assertForbidden();
        $this->putJson("/api/users/{$super->id}/role", ['role' => 'customer'])->assertForbidden();
        $this->getJson("/api/users/{$super->id}")->assertOk();

        $this->assertTrue($super->fresh()->isActive());
        $this->assertTrue($super->fresh()->isSuperAdmin());
        $this->assertNotSame('Pwned', $super->fresh()->name);

        $otherSuper = $this->actingAsRole(RoleName::SuperAdmin);
        $this->patchJson("/api/users/{$super->id}", ['name' => 'Renamed By Peer'])->assertOk();
    }

    // ---- Activation / deactivation -----------------------------------------

    public function test_default_admin_lacks_activation_permissions(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $target = $this->makeUser(RoleName::Customer);

        $this->postJson("/api/users/{$target->id}/deactivate")->assertForbidden();
        $this->postJson("/api/users/{$target->id}/activate")->assertForbidden();
    }

    public function test_deactivating_revokes_tokens_and_blocks_access_and_login(): void
    {
        $super = $this->actingAsRole(RoleName::SuperAdmin);
        $target = $this->makeUser(RoleName::Customer, ['password' => 'Str0ng!Passw0rd']);
        $plain = $target->createToken('t')->plainTextToken;

        $this->postJson("/api/users/{$target->id}/deactivate")
            ->assertOk()
            ->assertJsonPath('user.status', 'inactive');

        $this->assertFalse($target->fresh()->isActive());
        $this->assertSame(0, $target->tokens()->count());
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $super->id, 'action' => 'user.deactivated', 'subject_id' => $target->id]);

        $this->app['auth']->forgetGuards();
        $this->withToken($plain)->getJson('/api/user')->assertUnauthorized();

        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', '')->postJson('/api/login', ['email' => $target->email, 'password' => 'Str0ng!Passw0rd'])
            ->assertForbidden()->assertJsonPath('code', 'account_inactive');
    }

    public function test_activation_restores_access_and_is_idempotent(): void
    {
        $super = $this->actingAsRole(RoleName::SuperAdmin);
        $target = $this->makeUser(RoleName::Customer, ['password' => 'Str0ng!Passw0rd', 'status' => UserStatus::Inactive]);

        $this->postJson("/api/users/{$target->id}/activate")->assertOk()->assertJsonPath('user.status', 'active');
        $this->postJson("/api/users/{$target->id}/activate")->assertOk();

        $this->assertSame(1, AuditLog::where('action', 'user.activated')->count());

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/login', ['email' => $target->email, 'password' => 'Str0ng!Passw0rd'])->assertOk();
    }

    public function test_deactivating_twice_does_not_duplicate_audit_entries(): void
    {
        $this->actingAsRole(RoleName::SuperAdmin);
        $target = $this->makeUser(RoleName::Customer);

        $this->postJson("/api/users/{$target->id}/deactivate")->assertOk();
        $this->postJson("/api/users/{$target->id}/deactivate")->assertOk();

        $this->assertSame(1, AuditLog::where('action', 'user.deactivated')->count());
    }

    public function test_nobody_can_deactivate_themselves_not_even_a_super_admin(): void
    {
        $super = $this->actingAsRole(RoleName::SuperAdmin);

        $this->postJson("/api/users/{$super->id}/deactivate")
            ->assertUnprocessable()
            ->assertJsonPath('code', 'cannot_modify_self');

        $this->assertTrue($super->fresh()->isActive());
    }

    public function test_super_admin_can_deactivate_another_super_admin(): void
    {
        $this->actingAsRole(RoleName::SuperAdmin);
        $other = $this->makeUser(RoleName::SuperAdmin);

        $this->postJson("/api/users/{$other->id}/deactivate")->assertOk();

        $this->assertFalse($other->fresh()->isActive());
    }

    // ---- Role assignment ----------------------------------------------------

    public function test_default_admin_cannot_assign_roles(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $target = $this->makeUser(RoleName::Customer);

        $this->putJson("/api/users/{$target->id}/role", ['role' => 'agent'])->assertForbidden();
    }

    public function test_admin_with_permission_can_assign_only_non_privileged_roles(): void
    {
        $admin = $this->actingAsRole(RoleName::Admin, ['user.assign_role']);
        $target = $this->makeUser(RoleName::Customer);

        $this->putJson("/api/users/{$target->id}/role", ['role' => 'agent'])
            ->assertOk()->assertJsonPath('user.roles', ['agent']);

        foreach (['admin', 'super-admin'] as $privileged) {
            $this->putJson("/api/users/{$target->id}/role", ['role' => $privileged])
                ->assertUnprocessable()->assertJsonValidationErrors('role');
        }

        $this->assertSame(['agent'], $target->fresh()->getRoleNames()->all());
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $admin->id, 'action' => 'user.role_changed', 'subject_id' => $target->id]);
    }

    public function test_super_admin_can_promote_to_admin_and_role_is_replaced_not_added(): void
    {
        $this->actingAsRole(RoleName::SuperAdmin);
        $target = $this->makeUser(RoleName::Agent);

        $this->putJson("/api/users/{$target->id}/role", ['role' => 'admin'])->assertOk();

        $this->assertSame(['admin'], $target->fresh()->getRoleNames()->all());
    }

    public function test_nobody_can_change_their_own_role(): void
    {
        $super = $this->actingAsRole(RoleName::SuperAdmin);

        $this->putJson("/api/users/{$super->id}/role", ['role' => 'customer'])
            ->assertUnprocessable()->assertJsonPath('code', 'cannot_modify_self');

        $this->assertTrue($super->fresh()->isSuperAdmin());
    }

    public function test_role_change_takes_effect_on_the_next_request(): void
    {
        $super = $this->makeUser(RoleName::SuperAdmin);
        $agent = $this->makeUser(RoleName::Agent);
        $agentToken = $agent->createToken('t')->plainTextToken;

        $this->withToken($agentToken)->getJson('/api/users')->assertForbidden();

        $super->createToken('t'); // demote/promote through the API as super admin
        Sanctum::actingAs($super);
        $this->putJson("/api/users/{$agent->id}/role", ['role' => 'admin'])->assertOk();

        $this->app['auth']->forgetGuards();
        $this->withToken($agentToken)->getJson('/api/users')->assertOk();
    }
}
