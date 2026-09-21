<?php

namespace App\Http\Controllers\Api;

use App\Enums\LoginStatus;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\LoginActivity;
use App\Models\User;
use App\Support\Mailing;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    /**
     * Public registration. Always creates an active Customer; no role or
     * status input is accepted. The verification email is sent via the
     * Registered event, and no token is issued until the email is verified.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $user = DB::transaction(function () use ($request) {
            // The `hashed` cast on User hashes the password; do not hash it here.
            $user = new User($request->validated());
            $user->status = UserStatus::Active;
            $user->save();
            $user->assignRole(RoleName::Customer->value);

            return $user;
        });

        // If the mail server is down the account still exists; "resend verification" recovers it.
        Mailing::attempt(fn () => event(new Registered($user)));

        return response()->json([
            'message' => 'Registration successful. Please check your email to verify your account.',
            'user' => new UserResource($user),
        ], 201);
    }

    /**
     * Issue a Sanctum Bearer token. Credentials are checked before account
     * state, so status and verification details are never revealed to someone
     * who does not know the password.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->validated('email'))->first();

        // Always run a hash comparison so response time does not reveal whether the email exists.
        $passwordOk = Hash::check(
            $request->validated('password'),
            $user?->password ?? Hash::make('timing-equaliser'),
        );

        if (! $user || ! $passwordOk) {
            // Only attempts against real accounts are recorded; unknown emails are not stored at all.
            if ($user) {
                LoginActivity::record($user, LoginStatus::Failed, $request);
            }

            return response()->json([
                'message' => 'The provided credentials are incorrect.',
                'code' => 'invalid_credentials',
            ], 401);
        }

        if (! $user->isActive()) {
            LoginActivity::record($user, LoginStatus::Blocked, $request);

            return response()->json([
                'message' => 'Your account has been deactivated. Please contact support.',
                'code' => 'account_inactive',
            ], 403);
        }

        if (! $user->hasVerifiedEmail()) {
            LoginActivity::record($user, LoginStatus::Blocked, $request);

            return response()->json([
                'message' => 'Please verify your email address before signing in.',
                'code' => 'email_not_verified',
            ], 403);
        }

        $remember = $request->boolean('remember');
        $expiresAt = now()->addMinutes(
            $remember ? config('helpdesk.remember_token_ttl') : config('helpdesk.token_ttl'),
        );

        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->save();

        $token = $user->createToken($remember ? 'spa-remembered' : 'spa', ['*'], $expiresAt);

        LoginActivity::record($user, LoginStatus::Success, $request, $token->accessToken->id);

        return response()->json([
            'message' => 'Login successful.',
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $expiresAt->toIso8601String(),
            'user' => new UserResource($user),
        ]);
    }

    /** Revoke only the token used for this request. */
    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();

        // Record when this session ended (a transient token has no id, hence the null-safe read).
        LoginActivity::recordLogout($token->id ?? null);
        $token->delete();

        return response()->json(['message' => 'Logged out successfully.']);
    }

    /** The authenticated user with roles and permissions. */
    public function user(Request $request): JsonResponse
    {
        return response()->json(['user' => new UserResource($request->user())]);
    }
}
