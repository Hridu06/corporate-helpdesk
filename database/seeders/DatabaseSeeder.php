<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed roles and permissions only. The Super Admin is created explicitly via
     * `php artisan helpdesk:create-super-admin`, never by default seeding.
     */
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);
    }
}
