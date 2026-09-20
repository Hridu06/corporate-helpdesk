<?php

namespace Database\Seeders;

use App\Support\Permissions;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Idempotent. Creates missing roles/permissions and assigns default
     * permissions only when a role is first created, so admin customisations
     * made later are not overwritten on re-seed.
     */
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (Permissions::ALL as $name) {
            Permission::findOrCreate($name, 'web');
        }

        foreach (Permissions::roles() as $name) {
            $role = Role::findOrCreate($name, 'web');

            if ($role->wasRecentlyCreated && isset(Permissions::DEFAULTS[$name])) {
                $role->syncPermissions(Permissions::DEFAULTS[$name]);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
