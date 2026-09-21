<?php

namespace App\Http\Requests\Roles;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRolePermissionsRequest extends FormRequest
{
    /** Authorisation is declared on the route (`can:permission.assign`) and enforced again in RoleManager. */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Only the shape is checked here; RoleManager judges the meaning and explains each problem.
            'permissions' => ['present', 'array', 'max:100'],
            'permissions.*' => ['string', 'max:64'],
            // The role's state as the editor last saw it; a mismatch means someone else changed it since.
            'version' => ['required', 'string', 'max:32'],
        ];
    }
}
