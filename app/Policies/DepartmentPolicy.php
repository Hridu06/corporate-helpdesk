<?php

namespace App\Policies;

use App\Models\Department;
use App\Models\User;

/**
 * Users who can manage departments (create / update / delete) see all of them.
 * Anyone else with `department.view` (agents) sees only departments they belong to.
 */
class DepartmentPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('department.view');
    }

    public function view(User $actor, Department $department): bool
    {
        return $actor->can('department.view')
            && ($this->managesDepartments($actor) || $department->users()->whereKey($actor->id)->exists());
    }

    public function create(User $actor): bool
    {
        return $actor->can('department.create');
    }

    public function update(User $actor, Department $department): bool
    {
        return $actor->can('department.update');
    }

    public function delete(User $actor, Department $department): bool
    {
        return $actor->can('department.delete');
    }

    /** Class-level (staff picker) and model-level (sync members) use the same rule. */
    public function manageMembers(User $actor, ?Department $department = null): bool
    {
        return $actor->can('department.update');
    }

    public function managesDepartments(User $actor): bool
    {
        return $actor->canAny(['department.create', 'department.update', 'department.delete']);
    }
}
