<?php

namespace App\Support;

use App\Enums\RoleName;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Reading and changing what each role may do. Every rule lives here, on the server:
 *
 *  - Super Admin's permissions cannot be changed (it holds everything implicitly).
 *  - You may change only roles below your own, and only if you hold `permission.assign`.
 *  - You cannot grant a permission you do not hold yourself, so this cannot be used to climb.
 *  - Each role has a ceiling (the most it may hold) and a floor (what it must keep):
 *    see PermissionCatalog.
 *  - A change must be based on the current state (`version`), or it is refused as stale.
 *  - The change and its audit entry are written together, and take effect on the next request.
 */
final class RoleManager
{
    public const UPDATED = 'role.permissions_updated';

    public const RESET = 'role.permissions_reset';

    /** How high this person stands: the highest of their roles. */
    public function rankOf(User $user): int
    {
        return $user->getRoleNames()
            ->map(fn (string $name) => RoleName::tryFrom($name)?->rank() ?? 0)
            ->max() ?? 0;
    }

    /** Why this actor cannot change this role, or null if they can. */
    public function lockReason(RoleName $target, User $actor): ?string
    {
        if ($target === RoleName::SuperAdmin) {
            return 'Super Admin always has every permission and cannot be changed.';
        }

        if (! $actor->can('permission.assign')) {
            return 'You do not have permission to change roles.';
        }

        if ($target->rank() >= $this->rankOf($actor)) {
            return 'You cannot change your own role or a higher one.';
        }

        return null;
    }

    /** @param  list<string>  $names */
    public function versionOf(array $names): string
    {
        sort($names);

        return substr(sha1(implode(',', $names)), 0, 12);
    }

    /** @return list<string> */
    public function permissionsOf(Role $role): array
    {
        return $role->permissions()->pluck('name')->sort()->values()->all();
    }

    /** @return array<string, mixed> */
    public function describe(Role $role, User $actor): array
    {
        $name = RoleName::from($role->name);
        $permissions = $name === RoleName::SuperAdmin ? PermissionCatalog::ceiling($name) : $this->permissionsOf($role);
        $defaults = $name === RoleName::SuperAdmin ? $permissions : PermissionCatalog::defaults($name);
        sort($defaults);
        $lock = $this->lockReason($name, $actor);

        return [
            'name' => $name->value,
            'label' => $name->label(),
            'description' => $name->description(),
            'rank' => $name->rank(),
            'user_count' => User::role($name->value)->count(),
            'permissions' => $permissions,
            'version' => $this->versionOf($permissions),
            'default_permissions' => $defaults,
            'is_default' => $this->versionOf($permissions) === $this->versionOf($defaults),
            'editable' => $lock === null,
            'locked_reason' => $lock,
        ];
    }

    /**
     * The catalogue as this actor sees it: what each permission is, which roles may hold it,
     * which roles need it, and whether the actor could hand it out themselves.
     *
     * @return list<array<string, mixed>>
     */
    public function catalog(User $actor): array
    {
        return collect(PermissionCatalog::GROUPS)->map(fn (array $group, string $key) => [
            'key' => $key,
            'label' => $group['label'],
            'permissions' => collect($group['permissions'])->map(fn (array $details, string $name) => [
                'name' => $name,
                'label' => $details['label'],
                'description' => $details['description'],
                'sensitive' => $details['sensitive'] ?? false,
                'reserved' => $details['reserved'] ?? false,
                'allowed_for' => collect(PermissionCatalog::editableRoles())->filter(fn (RoleName $r) => in_array($name, PermissionCatalog::ceiling($r), true))->map(fn (RoleName $r) => $r->value)->values()->all(),
                'required_for' => collect(PermissionCatalog::editableRoles())->filter(fn (RoleName $r) => in_array($name, PermissionCatalog::floor($r), true))->map(fn (RoleName $r) => $r->value)->values()->all(),
                'can_grant' => $actor->can($name),
            ])->values()->all(),
        ])->values()->all();
    }

    /**
     * Replace a role's permissions with `$wanted`. Returns the role as described afterwards plus
     * what changed (nothing changed = nothing written and no audit entry).
     *
     * @param  list<string>  $wanted
     * @return array{role: array<string, mixed>, added: list<string>, removed: list<string>}
     */
    public function update(Role $role, User $actor, array $wanted, string $version, string $action = self::UPDATED, ?string $ip = null): array
    {
        $name = RoleName::from($role->name);

        if ($reason = $this->lockReason($name, $actor)) {
            throw new HttpResponseException(response()->json(['message' => $reason, 'code' => 'role_locked'], 403));
        }

        return DB::transaction(function () use ($role, $actor, $wanted, $version, $action, $ip, $name) {
            // Read under a lock, so two people saving at once cannot both win.
            $locked = Role::query()->lockForUpdate()->findOrFail($role->id);
            $current = $this->permissionsOf($locked);

            if (! hash_equals($this->versionOf($current), $version)) {
                throw new HttpResponseException(response()->json([
                    'message' => 'This role was changed by someone else. Reload to see the current permissions, then try again.',
                    'code' => 'stale_version',
                ], 409));
            }

            $wanted = array_values(array_unique($wanted));
            sort($wanted);

            if ($problems = $this->problems($name, $wanted, $current, $actor)) {
                throw ValidationException::withMessages(['permissions' => $problems]);
            }

            $added = array_values(array_diff($wanted, $current));
            $removed = array_values(array_diff($current, $wanted));

            if ($added !== [] || $removed !== []) {
                $locked->syncPermissions($wanted);
                app(PermissionRegistrar::class)->forgetCachedPermissions();

                AuditLog::record($actor, $action, $locked, ['role' => $name->value, 'added' => $added, 'removed' => $removed], $ip);
            }

            $locked->unsetRelation('permissions');

            return ['role' => $this->describe($locked, $actor), 'added' => $added, 'removed' => $removed];
        });
    }

    /**
     * Everything wrong with the requested set, in words a person can act on.
     *
     * @param  list<string>  $wanted
     * @param  list<string>  $current
     * @return list<string>
     */
    public function problems(RoleName $target, array $wanted, array $current, User $actor): array
    {
        $known = PermissionCatalog::flat();
        $ceiling = PermissionCatalog::ceiling($target);
        $problems = [];
        $describe = fn (string $name) => isset($known[$name]) ? "\"{$known[$name]['label']}\" ({$name})" : "\"{$name}\"";

        foreach ($wanted as $name) {
            if (! isset($known[$name])) {
                $problems[] = "{$describe($name)} is not a permission this system knows.";
            } elseif (! in_array($name, $ceiling, true)) {
                $problems[] = $known[$name]['reserved']
                    ? "{$describe($name)} is not used by any feature yet and cannot be given to anyone."
                    : "{$describe($name)} can never be given to the {$target->label()} role.";
            }
        }

        foreach (PermissionCatalog::floor($target) as $name) {
            if (! in_array($name, $wanted, true)) {
                $problems[] = "{$describe($name)} is required: the {$target->label()} role cannot work without it.";
            }
        }

        foreach (array_diff($wanted, $current) as $name) {
            if (isset($known[$name]) && ! $actor->can($name)) {
                $problems[] = "You cannot grant {$describe($name)} because you do not have it yourself.";
            }
        }

        return array_values(array_unique($problems));
    }

    /** The most recent changes to a role, newest first. */
    public function history(Role $role, int $limit = 20): array
    {
        return AuditLog::query()
            ->where('subject_type', 'Role')
            ->where('subject_id', $role->id)
            ->whereIn('action', [self::UPDATED, self::RESET])
            ->with('actor:id,name')
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'action' => $log->action === self::RESET ? 'reset' : 'updated',
                'actor' => $log->actor ? ['id' => $log->actor->id, 'name' => $log->actor->name] : null,
                'added' => $log->metadata['added'] ?? [],
                'removed' => $log->metadata['removed'] ?? [],
                'created_at' => $log->created_at,
            ])->all();
    }
}
