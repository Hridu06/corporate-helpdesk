<?php

namespace App\Http\Resources;

use App\Models\LoginActivity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LoginActivity */
class LoginActivityResource extends JsonResource
{
    /** `user` is null when the account was deleted. The API token link (token_id) is never exposed. */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user' => $this->user ? [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ] : null,
            'status' => $this->status,
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'login_at' => $this->login_at,
            'logout_at' => $this->logout_at,
        ];
    }
}
