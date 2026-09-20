<?php

namespace App\Policies;

use App\Models\User;

/**
 * Who may act on which user. Permissions come from Spatie; on top of that,
 * only a Super Admin may act on another Super Admin.
 *
 * Note: Gate::before grants Super Admins every ability, so invariants that must
 * hold for Super Admins too (no self-deactivation, no self role change) are
 * enforced in UserController, not here.
 */
class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('user.view');
    }

    public function view(User $actor, User $target): bool
    {
        return $actor->can('user.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('user.create');
    }

    public function update(User $actor, User $target): bool
    {
        return $actor->can('user.update') && $this->mayManage($actor, $target);
    }

    public function activate(User $actor, User $target): bool
    {
        return $actor->can('user.activate') && $this->mayManage($actor, $target);
    }

    public function deactivate(User $actor, User $target): bool
    {
        return $actor->can('user.deactivate') && $this->mayManage($actor, $target);
    }

    public function assignRole(User $actor, User $target): bool
    {
        return $actor->can('user.assign_role') && $this->mayManage($actor, $target);
    }

    private function mayManage(User $actor, User $target): bool
    {
        return ! $target->isSuperAdmin() || $actor->isSuperAdmin();
    }
}
