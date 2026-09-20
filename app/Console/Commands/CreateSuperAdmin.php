<?php

namespace App\Console\Commands;

use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class CreateSuperAdmin extends Command
{
    protected $signature = 'helpdesk:create-super-admin {--name=} {--email=}';

    protected $description = 'Create the initial Super Admin (CLI only, never exposed via the API)';

    public function handle(): int
    {
        $name = $this->option('name') ?: $this->ask('Name');
        $email = $this->option('email') ?: $this->ask('Email');
        $password = $this->secret('Password (input hidden)');

        $validator = Validator::make(
            compact('name', 'email', 'password'),
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255', 'unique:users,email'],
                'password' => ['required', Password::min(12)->mixedCase()->numbers()->symbols()],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $this->callSilent('db:seed', ['--class' => RolesAndPermissionsSeeder::class, '--force' => true]);

        $user = new User(['name' => $name, 'email' => $email, 'password' => $password]);
        $user->status = UserStatus::Active;
        $user->email_verified_at = now();
        $user->save();
        $user->assignRole(RoleName::SuperAdmin->value);

        $this->info("Super Admin created: {$user->email}");

        return self::SUCCESS;
    }
}
