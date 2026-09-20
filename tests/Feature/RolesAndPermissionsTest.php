<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\User;
use App\Support\Permissions;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RolesAndPermissionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_seeder_creates_all_roles_and_permissions(): void
    {
        $this->assertSame(count(Permissions::ALL), Permission::count());
        $this->assertSame(4, Role::count());
    }

    public function test_seeder_is_idempotent_and_keeps_customisations(): void
    {
        Role::findByName('admin')->revokePermissionTo('report.view');

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertSame(count(Permissions::ALL), Permission::count());
        $this->assertFalse(Role::findByName('admin')->hasPermissionTo('report.view'));
    }

    public function test_admin_does_not_have_super_admin_permissions(): void
    {
        $admin = User::factory()->create()->assignRole(RoleName::Admin->value);

        $this->assertTrue($admin->can('ticket.assign'));
        $this->assertFalse($admin->can('settings.manage'));
        $this->assertFalse($admin->can('role.delete'));
    }

    public function test_customer_and_agent_permissions_are_limited(): void
    {
        $customer = User::factory()->create()->assignRole(RoleName::Customer->value);
        $agent = User::factory()->create()->assignRole(RoleName::Agent->value);

        $this->assertTrue($customer->can('ticket.create'));
        $this->assertFalse($customer->can('ticket.internal_note'));
        $this->assertTrue($agent->can('ticket.internal_note'));
        $this->assertFalse($agent->can('ticket.assign'));
    }

    public function test_super_admin_has_every_permission_via_gate(): void
    {
        $super = User::factory()->create()->assignRole(RoleName::SuperAdmin->value);

        foreach (Permissions::ALL as $permission) {
            $this->assertTrue($super->can($permission), $permission);
        }
    }

    public function test_inactive_super_admin_gets_no_gate_bypass(): void
    {
        $super = User::factory()->create()->assignRole(RoleName::SuperAdmin->value);
        $super->status = UserStatus::Inactive;
        $super->save();

        $this->assertFalse($super->can('settings.manage'));
    }

    public function test_privileged_fields_are_not_mass_assignable(): void
    {
        $user = new User(['name' => 'X', 'email' => 'x@example.com', 'password' => 'secret', 'status' => 'inactive', 'is_admin' => true]);

        $this->assertNull($user->status);
        $this->assertArrayNotHasKey('is_admin', $user->getAttributes());
    }

    public function test_password_is_hashed_exactly_once(): void
    {
        $user = User::factory()->create(['password' => 'Plain-Text-1']);

        $this->assertNotSame('Plain-Text-1', $user->password);
        $this->assertTrue(password_verify('Plain-Text-1', $user->password));
    }

    public function test_create_super_admin_command(): void
    {
        $this->artisan('helpdesk:create-super-admin', ['--name' => 'Root', '--email' => 'root@example.com'])
            ->expectsQuestion('Password (input hidden)', 'Str0ng!Passw0rd#')
            ->assertSuccessful();

        $user = User::where('email', 'root@example.com')->firstOrFail();
        $this->assertTrue($user->isSuperAdmin());
        $this->assertTrue($user->isActive());
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_create_super_admin_command_rejects_weak_password(): void
    {
        $this->artisan('helpdesk:create-super-admin', ['--name' => 'Root', '--email' => 'root@example.com'])
            ->expectsQuestion('Password (input hidden)', 'weak')
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }
}
