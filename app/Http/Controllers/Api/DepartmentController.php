<?php

namespace App\Http\Controllers\Api;

use App\Enums\RoleName;
use App\Http\Controllers\Controller;
use App\Http\Requests\Departments\StoreDepartmentRequest;
use App\Http\Requests\Departments\UpdateDepartmentRequest;
use App\Http\Resources\DepartmentResource;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\User;
use App\Policies\DepartmentPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Authorisation is declared on the routes (`can:` -> DepartmentPolicy). The
 * listing is additionally scoped here: only managers see every department.
 */
class DepartmentController extends Controller
{
    /** Roles that can belong to a department. Customers never can. */
    private const STAFF_ROLES = [RoleName::Agent->value, RoleName::Admin->value];

    public function index(Request $request, DepartmentPolicy $policy)
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $actor = $request->user();

        $departments = Department::query()
            ->withCount('users')
            ->unless($policy->managesDepartments($actor), fn ($query) => $query->whereHas('users', fn ($q) => $q->whereKey($actor->id)))
            ->when($filters['search'] ?? null, function ($query, string $term) {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $query->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('description', 'like', $like));
            })
            ->when(array_key_exists('is_active', $filters) && $filters['is_active'] !== null, fn ($query) => $query->where('is_active', (bool) $filters['is_active']))
            ->orderBy('name')
            ->paginate($filters['per_page'] ?? 15);

        return DepartmentResource::collection($departments);
    }

    public function show(Department $department): JsonResponse
    {
        $department->loadCount('users')->load('users.roles');

        return response()->json(['department' => new DepartmentResource($department)]);
    }

    public function store(StoreDepartmentRequest $request): JsonResponse
    {
        // Default set here too: the DB default is not present on the in-memory model.
        $department = Department::create(['is_active' => true, ...$request->validated()]);

        AuditLog::record($request->user(), 'department.created', $department, ['name' => $department->name], $request->ip());

        return response()->json([
            'message' => 'Department created.',
            'department' => new DepartmentResource($department->loadCount('users')),
        ], 201);
    }

    public function update(UpdateDepartmentRequest $request, Department $department): JsonResponse
    {
        $department->fill($request->validated());
        $changed = array_keys($department->getDirty());
        $department->save();

        if ($changed) {
            AuditLog::record($request->user(), 'department.updated', $department, ['fields' => $changed], $request->ip());
        }

        return response()->json([
            'message' => 'Department updated.',
            'department' => new DepartmentResource($department->loadCount('users')),
        ]);
    }

    /** A department with members cannot be deleted; deactivate it instead. */
    public function destroy(Request $request, Department $department): JsonResponse
    {
        if ($department->users()->exists()) {
            return response()->json([
                'message' => 'This department still has members. Remove them or deactivate the department instead.',
                'code' => 'department_has_members',
            ], 422);
        }

        AuditLog::record($request->user(), 'department.deleted', $department, ['name' => $department->name], $request->ip());
        $department->delete();

        return response()->json(['message' => 'Department deleted.']);
    }

    /** Staff who can be added to a department (drives the member picker). */
    public function assignableStaff(): JsonResponse
    {
        $staff = User::query()
            ->role(self::STAFF_ROLES)
            ->where('status', 'active')
            ->with('roles')
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $user->getRoleNames()->values(),
            ]);

        return response()->json(['staff' => $staff]);
    }

    /** Replace the department's member list. Only active agents and admins are eligible. */
    public function updateMembers(Request $request, Department $department): JsonResponse
    {
        $data = $request->validate([
            'user_ids' => ['present', 'array', 'max:500'],
            'user_ids.*' => ['integer', 'distinct'],
        ]);

        $requested = collect($data['user_ids']);
        $eligible = User::query()
            ->role(self::STAFF_ROLES)
            ->where('status', 'active')
            ->whereIn('id', $requested)
            ->pluck('id');

        if ($eligible->count() !== $requested->count()) {
            throw ValidationException::withMessages([
                'user_ids' => 'Only active agents and admins can be department members.',
            ]);
        }

        $changes = $department->users()->sync($eligible->all());

        if ($changes['attached'] || $changes['detached']) {
            AuditLog::record($request->user(), 'department.members_changed', $department, [
                'added' => $changes['attached'],
                'removed' => $changes['detached'],
            ], $request->ip());
        }

        return response()->json([
            'message' => 'Members updated.',
            'department' => new DepartmentResource($department->loadCount('users')->load('users.roles')),
        ]);
    }
}
