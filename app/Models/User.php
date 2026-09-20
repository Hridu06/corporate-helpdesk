<?php

namespace App\Models;

use App\Enums\RoleName;
use App\Enums\UserStatus;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Traits\HasRoles;

// status, last_login_* and roles are intentionally NOT fillable: backend code sets them explicitly.
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            // The 'hashed' cast hashes on assignment; never call Hash::make() before setting it.
            'password' => 'hashed',
            'status' => UserStatus::class,
        ];
    }

    /** Departments this staff member belongs to. */
    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class)->withTimestamps();
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole(RoleName::SuperAdmin->value);
    }

    /**
     * Roles this user may hand out. Only a Super Admin can grant privileged
     * roles (admin / super-admin); everyone else is limited to agent and customer.
     *
     * @return list<string>
     */
    public function assignableRoles(): array
    {
        if ($this->isSuperAdmin()) {
            return Role::query()->pluck('name')->all();
        }

        return [RoleName::Agent->value, RoleName::Customer->value];
    }
}
