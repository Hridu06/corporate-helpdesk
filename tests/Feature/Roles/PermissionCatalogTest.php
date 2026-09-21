<?php

namespace Tests\Feature\Roles;

use App\Enums\RoleName;
use App\Support\PermissionCatalog;
use App\Support\Permissions;
use Tests\TestCase;

/** Keeps the rulebook consistent with the permission list and the shipped defaults. */
class PermissionCatalogTest extends TestCase
{
    public function test_every_permission_is_described_exactly_once(): void
    {
        $described = array_keys(PermissionCatalog::flat());

        $this->assertEqualsCanonicalizing(Permissions::ALL, $described);
        $this->assertSame(count(Permissions::ALL), count($described));
    }

    public function test_every_entry_has_a_label_and_a_description(): void
    {
        foreach (PermissionCatalog::flat() as $name => $details) {
            $this->assertNotSame('', trim($details['label']), $name);
            $this->assertNotSame('', trim($details['description']), $name);
        }
    }

    public function test_the_shipped_defaults_respect_every_ceiling_and_floor(): void
    {
        foreach (PermissionCatalog::editableRoles() as $role) {
            $defaults = PermissionCatalog::defaults($role);

            $this->assertSame([], array_values(array_diff($defaults, PermissionCatalog::ceiling($role))), "{$role->value} defaults exceed the ceiling");
            $this->assertSame([], array_values(array_diff(PermissionCatalog::floor($role), $defaults)), "{$role->value} defaults miss the floor");
        }
    }

    public function test_floors_sit_inside_ceilings(): void
    {
        foreach (PermissionCatalog::editableRoles() as $role) {
            $this->assertSame([], array_values(array_diff(PermissionCatalog::floor($role), PermissionCatalog::ceiling($role))), $role->value);
        }
    }

    public function test_no_ceiling_includes_a_reserved_permission(): void
    {
        $reserved = array_keys(array_filter(PermissionCatalog::flat(), fn ($p) => $p['reserved']));

        $this->assertNotEmpty($reserved);
        foreach (PermissionCatalog::editableRoles() as $role) {
            $this->assertSame([], array_values(array_intersect($reserved, PermissionCatalog::ceiling($role))), $role->value);
        }
    }

    public function test_the_customer_can_never_hold_staff_permissions(): void
    {
        $forbidden = ['ticket.view_all', 'ticket.internal_note', 'ticket.assign', 'ticket.update_status', 'ticket.update_priority', 'user.view', 'user.assign_role', 'report.view', 'activity.view', 'role.view', 'permission.assign', 'department.view'];

        $this->assertSame([], array_values(array_intersect($forbidden, PermissionCatalog::ceiling(RoleName::Customer))));
    }

    public function test_only_the_admin_may_ever_hold_the_permissions_that_run_the_helpdesk(): void
    {
        foreach (['user.view', 'user.create', 'user.assign_role', 'activity.view', 'role.view', 'permission.assign', 'department.create', 'department.delete'] as $name) {
            $this->assertNotContains($name, PermissionCatalog::ceiling(RoleName::Agent), $name);
            $this->assertContains($name, PermissionCatalog::ceiling(RoleName::Admin), $name);
        }
    }

    public function test_roles_are_ranked_from_the_customer_up_to_the_super_admin(): void
    {
        $this->assertGreaterThan(RoleName::Admin->rank(), RoleName::SuperAdmin->rank());
        $this->assertGreaterThan(RoleName::Agent->rank(), RoleName::Admin->rank());
        $this->assertGreaterThan(RoleName::Customer->rank(), RoleName::Agent->rank());
        $this->assertSame([RoleName::Admin, RoleName::Agent, RoleName::Customer], PermissionCatalog::editableRoles());
    }
}
