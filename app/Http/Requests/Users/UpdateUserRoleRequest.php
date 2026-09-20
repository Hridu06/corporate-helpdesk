<?php

namespace App\Http\Requests\Users;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Enforced by the route's `can:assignRole,user` middleware.
    }

    public function rules(): array
    {
        return [
            'role' => ['required', 'string', Rule::in($this->user()->assignableRoles())],
        ];
    }
}
