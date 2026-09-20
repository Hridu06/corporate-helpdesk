<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class PasswordResetController extends Controller
{
    /**
     * Email a reset link. The response is identical whether or not the address
     * belongs to an account, so it cannot be used to enumerate users. Nothing
     * is sent for deactivated accounts.
     */
    public function forgot(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'string', 'email']]);
        $email = Str::lower(trim($data['email']));

        $user = User::where('email', $email)->first();

        if ($user?->isActive()) {
            // Throttled/failed broker statuses are deliberately not exposed.
            Password::sendResetLink(['email' => $email]);
        }

        return response()->json([
            'message' => 'If an account exists for that email, a password reset link has been sent.',
        ], 202);
    }

    /**
     * Set a new password using the emailed token (single use, expires per
     * config('auth.passwords.users.expire')). All API tokens are revoked so
     * every existing session must sign in again. Every failure (unknown email,
     * bad/expired/used token, deactivated account) returns the same response.
     */
    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $user = User::where('email', $request->validated('email'))->first();

        $status = $user?->isActive()
            ? Password::reset(
                $request->only('email', 'password', 'password_confirmation', 'token'),
                function (User $user, string $password) {
                    // The `hashed` cast hashes the password on assignment.
                    $user->forceFill([
                        'password' => $password,
                        'remember_token' => Str::random(60),
                    ])->save();

                    $user->tokens()->delete();

                    event(new PasswordReset($user));
                },
            )
            : Password::INVALID_USER;

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json([
                'message' => 'This password reset link is invalid or has expired.',
                'code' => 'invalid_reset_token',
            ], 422);
        }

        return response()->json(['message' => 'Your password has been reset. You can now sign in.']);
    }
}
