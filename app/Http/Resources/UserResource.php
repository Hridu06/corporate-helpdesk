<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Spatie\Permission\Models\Permission;

/** @mixin \App\Models\User */
class UserResource extends JsonResource
{
    /**
     * Exposes only what the client needs. Roles and permissions are for UI
     * decisions; the backend re-checks every action itself.
     */
    public function toArray(Request $request): array
    {
        $permissions = $this->isSuperAdmin()
            ? Permission::query()->pluck('name')
            : $this->getAllPermissions()->pluck('name');

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'email_verified_at' => $this->email_verified_at,
            'status' => $this->status,
            'roles' => $this->getRoleNames()->values(),
            'permissions' => $permissions->sort()->values(),
        ];
    }
}
