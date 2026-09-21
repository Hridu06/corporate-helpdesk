<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Users\StoreUserRequest;
use App\Http\Requests\Users\UpdateUserRequest;
use App\Http\Requests\Users\UpdateUserRoleRequest;
use App\Http\Resources\AdminUserResource;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Mailing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Administrative user management. Authorisation is declared on the routes
 * (`can:` middleware -> UserPolicy); the invariants that must also bind Super
 * Admins (self-protection) live here because Gate::before bypasses policies.
 */
class UserController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:'.implode(',', array_column(UserStatus::cases(), 'value'))],
            'role' => ['nullable', 'string', 'max:64'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $users = User::query()
            ->with('roles')
            ->when($filters['search'] ?? null, function ($query, string $term) {
                // Escape LIKE wildcards so the term is matched literally.
                $like = '%'.addcslashes($term, '%_\\').'%';
                $query->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('email', 'like', $like));
            })
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['role'] ?? null, fn ($query, string $role) => $query->role($role))
            ->latest('id')
            ->paginate($filters['per_page'] ?? 15);

        return AdminUserResource::collection($users);
    }

    /** Roles the current user may hand out (drives the role dropdowns in the UI). */
    public function assignableRoles(Request $request): JsonResponse
    {
        return response()->json(['roles' => $request->user()->assignableRoles()]);
    }

    public function show(User $user): JsonResponse
    {
        return response()->json(['user' => new AdminUserResource($user->load('roles'))]);
    }

    /**
     * Create an account. The admin never chooses a password: the account gets an
     * unusable random one and the person receives a link to set their own. The
     * email is marked verified because an authorised admin vouches for it.
     */
    public function store(StoreUserRequest $request): JsonResponse
    {
        $data = $request->validated();

        $user = DB::transaction(function () use ($data) {
            $user = new User(['name' => $data['name'], 'email' => $data['email'], 'password' => Str::random(64)]);
            $user->status = UserStatus::Active;
            $user->email_verified_at = now();
            $user->save();
            $user->assignRole($data['role']);

            return $user;
        });

        AuditLog::record($request->user(), 'user.created', $user, ['role' => $data['role']], $request->ip());
        $sent = Mailing::attempt(fn () => Password::sendResetLink(['email' => $user->email]));

        return response()->json([
            // The admin is told when the mail failed; the account exists either way.
            'message' => $sent
                ? 'User created. A link to set their password has been emailed.'
                : 'User created, but the email could not be sent. Ask them to use "Forgot password" once mail is working.',
            'email_sent' => $sent,
            'user' => new AdminUserResource($user->load('roles')),
        ], 201);
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $user->fill($request->validated());
        $changed = array_keys($user->getDirty());
        $emailChanged = $user->isDirty('email');

        if ($emailChanged) {
            // New address must be proven; old sessions must not outlive the identity change.
            $user->email_verified_at = null;
        }

        $user->save();

        if ($emailChanged) {
            $user->tokens()->delete();
            Mailing::attempt(fn () => $user->sendEmailVerificationNotification());
        }

        if ($changed) {
            AuditLog::record($request->user(), 'user.updated', $user, ['fields' => $changed], $request->ip());
        }

        return response()->json(['message' => 'User updated.', 'user' => new AdminUserResource($user->load('roles'))]);
    }

    public function activate(Request $request, User $user): JsonResponse
    {
        if (! $user->isActive()) {
            $user->status = UserStatus::Active;
            $user->save();
            AuditLog::record($request->user(), 'user.activated', $user, [], $request->ip());
        }

        return response()->json(['message' => 'User activated.', 'user' => new AdminUserResource($user->load('roles'))]);
    }

    public function deactivate(Request $request, User $user): JsonResponse
    {
        if ($user->is($request->user())) {
            return $this->refuse('You cannot deactivate your own account.', 'cannot_modify_self');
        }

        if ($user->isActive()) {
            $user->status = UserStatus::Inactive;
            $user->save();
            $user->tokens()->delete(); // End every existing session immediately.
            AuditLog::record($request->user(), 'user.deactivated', $user, [], $request->ip());
        }

        return response()->json(['message' => 'User deactivated.', 'user' => new AdminUserResource($user->load('roles'))]);
    }

    public function updateRole(UpdateUserRoleRequest $request, User $user): JsonResponse
    {
        if ($user->is($request->user())) {
            return $this->refuse('You cannot change your own role.', 'cannot_modify_self');
        }

        $from = $user->getRoleNames()->all();
        $to = $request->validated('role');

        if ($from !== [$to]) {
            $user->syncRoles([$to]);
            AuditLog::record($request->user(), 'user.role_changed', $user, ['from' => $from, 'to' => $to], $request->ip());
        }

        return response()->json(['message' => 'Role updated.', 'user' => new AdminUserResource($user->load('roles'))]);
    }

    private function refuse(string $message, string $code): JsonResponse
    {
        return response()->json(['message' => $message, 'code' => $code], 422);
    }
}
