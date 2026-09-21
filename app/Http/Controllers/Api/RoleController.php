<?php

namespace App\Http\Controllers\Api;

use App\Enums\RoleName;
use App\Http\Controllers\Controller;
use App\Http\Requests\Roles\UpdateRolePermissionsRequest;
use App\Support\PermissionCatalog;
use App\Support\RoleManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;

/**
 * What each role may do. Reading needs `role.view`; changing needs `permission.assign`. Every
 * rule about who may change what is in RoleManager, whatever the route says.
 */
class RoleController extends Controller
{
    public function __construct(private readonly RoleManager $roles) {}

    /** The four roles, as this person may see and change them. */
    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();

        return response()->json([
            'roles' => collect(RoleName::cases())
                ->map(fn (RoleName $name) => $this->roles->describe($this->find($name->value), $actor))
                ->all(),
        ]);
    }

    /** What each permission means and which roles may hold it. */
    public function permissions(Request $request): JsonResponse
    {
        return response()->json(['groups' => $this->roles->catalog($request->user())]);
    }

    public function update(UpdateRolePermissionsRequest $request, string $role): JsonResponse
    {
        $result = $this->roles->update(
            $this->find($role),
            $request->user(),
            $request->validated('permissions'),
            $request->validated('version'),
            RoleManager::UPDATED,
            $request->ip(),
        );

        return response()->json([
            'message' => $result['added'] || $result['removed'] ? 'Permissions updated.' : 'Nothing changed.',
            ...$result,
        ]);
    }

    /** Put a role back to the permissions it ships with. Same rules as any other change. */
    public function reset(Request $request, string $role): JsonResponse
    {
        $request->validate(['version' => ['required', 'string', 'max:32']]);
        $model = $this->find($role);
        $name = RoleName::from($model->name);

        $result = $this->roles->update(
            $model,
            $request->user(),
            PermissionCatalog::defaults($name),
            $request->input('version'),
            RoleManager::RESET,
            $request->ip(),
        );

        return response()->json([
            'message' => $result['added'] || $result['removed'] ? 'Permissions reset to the defaults.' : 'Already at the defaults.',
            ...$result,
        ]);
    }

    public function history(string $role): JsonResponse
    {
        return response()->json(['data' => $this->roles->history($this->find($role))]);
    }

    /** Only the four built-in roles exist here; anything else is a 404. */
    private function find(string $name): Role
    {
        $known = RoleName::tryFrom($name);
        abort_if($known === null, 404, 'Role not found.');

        return Role::findByName($known->value, 'web');
    }
}
