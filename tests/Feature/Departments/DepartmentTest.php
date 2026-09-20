<?php

namespace Tests\Feature\Departments;

use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\Department;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DepartmentTest extends TestCase
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
        $department = Department::factory()->create();

        $this->getJson('/api/departments')->assertUnauthorized();
        $this->postJson('/api/departments', [])->assertUnauthorized();
        $this->getJson("/api/departments/{$department->id}")->assertUnauthorized();
        $this->patchJson("/api/departments/{$department->id}", [])->assertUnauthorized();
        $this->deleteJson("/api/departments/{$department->id}")->assertUnauthorized();
        $this->putJson("/api/departments/{$department->id}/members", ['user_ids' => []])->assertUnauthorized();
        $this->getJson('/api/departments/assignable-staff')->assertUnauthorized();
    }

    public function test_customers_have_no_access_to_departments(): void
    {
        $department = Department::factory()->create();
        $this->actingAsRole(RoleName::Customer);

        $this->getJson('/api/departments')->assertForbidden();
        $this->getJson("/api/departments/{$department->id}")->assertForbidden();
        $this->postJson('/api/departments', ['name' => 'X'])->assertForbidden();
        $this->deleteJson("/api/departments/{$department->id}")->assertForbidden();
    }

    // ---- Visibility scoping --------------------------------------------------

    public function test_an_agent_sees_only_their_own_departments(): void
    {
        $mine = Department::factory()->create(['name' => 'Billing']);
        $other = Department::factory()->create(['name' => 'Legal']);
        $agent = $this->actingAsRole(RoleName::Agent);
        $mine->users()->attach($agent);

        $this->getJson('/api/departments')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Billing');
        $this->getJson("/api/departments/{$mine->id}")->assertOk()->assertJsonPath('department.name', 'Billing');
        $this->getJson("/api/departments/{$other->id}")->assertForbidden();
    }

    public function test_an_agent_in_no_department_sees_an_empty_list(): void
    {
        Department::factory()->count(2)->create();
        $this->actingAsRole(RoleName::Agent);

        $this->getJson('/api/departments')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_agents_cannot_change_anything(): void
    {
        $department = Department::factory()->create();
        $agent = $this->actingAsRole(RoleName::Agent);
        $department->users()->attach($agent);

        $this->patchJson("/api/departments/{$department->id}", ['name' => 'Renamed'])->assertForbidden();
        $this->deleteJson("/api/departments/{$department->id}")->assertForbidden();
        $this->putJson("/api/departments/{$department->id}/members", ['user_ids' => []])->assertForbidden();
        $this->getJson('/api/departments/assignable-staff')->assertForbidden();
        $this->assertSame(1, $department->users()->count());
    }

    public function test_a_default_admin_sees_every_department(): void
    {
        Department::factory()->count(3)->create();
        $this->actingAsRole(RoleName::Admin);

        $this->getJson('/api/departments')->assertOk()->assertJsonCount(3, 'data');
    }

    // ---- Listing -------------------------------------------------------------

    public function test_listing_shape_search_status_filter_and_pagination(): void
    {
        $this->actingAsRole(RoleName::SuperAdmin);
        $billing = Department::factory()->create(['name' => 'Billing', 'description' => 'Invoices and refunds']);
        Department::factory()->create(['name' => 'Technical', 'description' => 'Bugs']);
        Department::factory()->inactive()->create(['name' => 'Archive']);
        $billing->users()->attach($this->makeUser(RoleName::Agent));

        $this->getJson('/api/departments')->assertOk()
            ->assertJsonStructure(['data' => [['id', 'name', 'description', 'is_active', 'members_count', 'created_at']], 'meta' => ['total', 'per_page']])
            ->assertJsonPath('data.0.name', 'Archive'); // alphabetical

        $this->getJson('/api/departments?search=invoices')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.members_count', 1);
        $this->getJson('/api/departments?is_active=0')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Archive');
        $this->getJson('/api/departments?is_active=1')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/departments?per_page=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 3);
        $this->getJson('/api/departments?search=%25')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/departments?per_page=1000')->assertUnprocessable();
    }

    public function test_show_includes_members_without_sensitive_fields(): void
    {
        $this->actingAsRole(RoleName::SuperAdmin);
        $department = Department::factory()->create();
        $department->users()->attach($this->makeUser(RoleName::Agent, ['name' => 'Alex Agent']));

        $response = $this->getJson("/api/departments/{$department->id}")->assertOk();

        $response->assertJsonPath('department.members.0.name', 'Alex Agent')
            ->assertJsonPath('department.members.0.roles', ['agent']);
        $this->assertStringNotContainsString('password', json_encode($response->json()));
    }

    // ---- Create / update / delete -------------------------------------------

    public function test_default_admin_cannot_create_or_delete_but_can_update(): void
    {
        $department = Department::factory()->create(['name' => 'Old']);
        $this->actingAsRole(RoleName::Admin);

        $this->postJson('/api/departments', ['name' => 'New'])->assertForbidden();
        $this->deleteJson("/api/departments/{$department->id}")->assertForbidden();
        $this->patchJson("/api/departments/{$department->id}", ['name' => 'Renamed', 'is_active' => false])
            ->assertOk()->assertJsonPath('department.name', 'Renamed')->assertJsonPath('department.is_active', false);
    }

    public function test_super_admin_can_create_with_audit_trail(): void
    {
        $super = $this->actingAsRole(RoleName::SuperAdmin);

        $this->postJson('/api/departments', ['name' => '  Billing  ', 'description' => 'Money things'])
            ->assertCreated()
            ->assertJsonPath('department.name', 'Billing')
            ->assertJsonPath('department.is_active', true)
            ->assertJsonPath('department.members_count', 0);

        $this->assertDatabaseHas('audit_logs', ['actor_id' => $super->id, 'action' => 'department.created', 'subject_type' => 'Department']);
    }

    public function test_an_admin_with_the_create_permission_can_create(): void
    {
        $this->actingAsRole(RoleName::Admin, ['department.create']);

        $this->postJson('/api/departments', ['name' => 'Sales'])->assertCreated();
    }

    public function test_create_and_update_validate_input(): void
    {
        $this->actingAsRole(RoleName::SuperAdmin);
        Department::factory()->create(['name' => 'Billing']);
        $other = Department::factory()->create(['name' => 'Legal']);

        $this->postJson('/api/departments', [])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson('/api/departments', ['name' => 'Billing'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson('/api/departments', ['name' => str_repeat('a', 101)])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson('/api/departments', ['name' => 'Ok', 'description' => str_repeat('a', 501)])->assertUnprocessable()->assertJsonValidationErrors('description');
        $this->postJson('/api/departments', ['name' => 'Ok', 'is_active' => 'maybe'])->assertUnprocessable()->assertJsonValidationErrors('is_active');

        $this->patchJson("/api/departments/{$other->id}", ['name' => 'Billing'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->patchJson("/api/departments/{$other->id}", ['name' => 'Legal'])->assertOk(); // its own name is fine
    }

    public function test_update_records_only_real_changes_in_the_audit_log(): void
    {
        $this->actingAsRole(RoleName::SuperAdmin);
        $department = Department::factory()->create(['name' => 'Same', 'description' => 'x']);

        $this->patchJson("/api/departments/{$department->id}", ['name' => 'Same'])->assertOk();
        $this->assertDatabaseMissing('audit_logs', ['action' => 'department.updated']);

        $this->patchJson("/api/departments/{$department->id}", ['description' => 'y'])->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'department.updated', 'subject_id' => $department->id]);
    }

    public function test_an_empty_department_can_be_deleted(): void
    {
        $super = $this->actingAsRole(RoleName::SuperAdmin);
        $department = Department::factory()->create();

        $this->deleteJson("/api/departments/{$department->id}")->assertOk();

        $this->assertModelMissing($department);
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $super->id, 'action' => 'department.deleted']);
    }

    public function test_a_department_with_members_cannot_be_deleted(): void
    {
        $this->actingAsRole(RoleName::SuperAdmin);
        $department = Department::factory()->create();
        $department->users()->attach($this->makeUser(RoleName::Agent));

        $this->deleteJson("/api/departments/{$department->id}")
            ->assertUnprocessable()->assertJsonPath('code', 'department_has_members');

        $this->assertModelExists($department);
    }

    // ---- Members -------------------------------------------------------------

    public function test_assignable_staff_lists_only_active_agents_and_admins(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $agent = $this->makeUser(RoleName::Agent, ['name' => 'Agent A']);
        $this->makeUser(RoleName::Customer, ['name' => 'Customer C']);
        $this->makeUser(RoleName::Agent, ['name' => 'Inactive I', 'status' => UserStatus::Inactive]);

        $response = $this->getJson('/api/departments/assignable-staff')->assertOk();

        $names = collect($response->json('staff'))->pluck('name');
        $this->assertTrue($names->contains('Agent A'));
        $this->assertFalse($names->contains('Customer C'));
        $this->assertFalse($names->contains('Inactive I'));
        $this->assertStringNotContainsString('password', json_encode($response->json()));
        $this->assertNotNull($agent);
    }

    public function test_members_can_be_replaced_and_changes_are_audited(): void
    {
        $admin = $this->actingAsRole(RoleName::Admin);
        $department = Department::factory()->create();
        [$a, $b, $c] = [$this->makeUser(RoleName::Agent), $this->makeUser(RoleName::Agent), $this->makeUser(RoleName::Admin)];
        $department->users()->attach($a);

        $this->putJson("/api/departments/{$department->id}/members", ['user_ids' => [$b->id, $c->id]])
            ->assertOk()->assertJsonPath('department.members_count', 2);

        $this->assertEqualsCanonicalizing([$b->id, $c->id], $department->users()->pluck('users.id')->all());
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $admin->id, 'action' => 'department.members_changed', 'subject_id' => $department->id]);
    }

    public function test_an_unchanged_member_list_is_not_audited(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $department = Department::factory()->create();
        $agent = $this->makeUser(RoleName::Agent);
        $department->users()->attach($agent);

        $this->putJson("/api/departments/{$department->id}/members", ['user_ids' => [$agent->id]])->assertOk();

        $this->assertDatabaseMissing('audit_logs', ['action' => 'department.members_changed']);
    }

    public function test_the_member_list_can_be_emptied(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $department = Department::factory()->create();
        $department->users()->attach($this->makeUser(RoleName::Agent));

        $this->putJson("/api/departments/{$department->id}/members", ['user_ids' => []])->assertOk();

        $this->assertSame(0, $department->users()->count());
    }

    public function test_customers_and_inactive_users_cannot_be_members(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $department = Department::factory()->create();
        $customer = $this->makeUser(RoleName::Customer);
        $inactive = $this->makeUser(RoleName::Agent, ['status' => UserStatus::Inactive]);
        $agent = $this->makeUser(RoleName::Agent);

        foreach ([[$customer->id], [$inactive->id], [$agent->id, $customer->id], [999999]] as $ids) {
            $this->putJson("/api/departments/{$department->id}/members", ['user_ids' => $ids])
                ->assertUnprocessable()->assertJsonValidationErrors('user_ids');
        }

        $this->assertSame(0, $department->users()->count());
    }

    public function test_member_payload_is_validated(): void
    {
        $this->actingAsRole(RoleName::Admin);
        $department = Department::factory()->create();

        $this->putJson("/api/departments/{$department->id}/members", [])->assertUnprocessable()->assertJsonValidationErrors('user_ids');
        $this->putJson("/api/departments/{$department->id}/members", ['user_ids' => 'nope'])->assertUnprocessable()->assertJsonValidationErrors('user_ids');
        $this->putJson("/api/departments/{$department->id}/members", ['user_ids' => ['abc']])->assertUnprocessable();
    }

    public function test_deleting_a_user_removes_them_from_departments(): void
    {
        $department = Department::factory()->create();
        $agent = $this->makeUser(RoleName::Agent);
        $department->users()->attach($agent);

        $agent->delete();

        $this->assertSame(0, $department->users()->count());
    }

    public function test_an_inactive_super_admin_gets_no_access(): void
    {
        $super = $this->actingAsRole(RoleName::SuperAdmin);
        $super->forceFill(['status' => UserStatus::Inactive])->save();

        $this->getJson('/api/departments')->assertForbidden()->assertJsonPath('code', 'account_inactive');
    }
}
