<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Mailing;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class EmailVerificationController extends Controller
{
    /**
     * Target of the link in the verification email (opened in a browser, so it
     * redirects to the SPA with a status instead of returning JSON).
     */
    public function verify(Request $request, string $id, string $hash): RedirectResponse
    {
        $user = User::find($id);

        $valid = $request->hasValidSignature()
            && $user
            && hash_equals(sha1($user->getEmailForVerification()), $hash);

        if (! $valid) {
            return $this->redirectToFrontend('invalid');
        }

        if (! $user->hasVerifiedEmail() && $user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        return $this->redirectToFrontend('success');
    }

    /**
     * Resend the verification email. The response is identical whether or not
     * the address is registered, so it cannot be used to enumerate accounts.
     */
    public function resend(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'string', 'email']]);

        $user = User::where('email', Str::lower(trim($data['email'])))->first();

        if ($user && $user->isActive() && ! $user->hasVerifiedEmail()) {
            // Same answer whether or not the mail could be sent (see Mailing).
            Mailing::attempt(fn () => $user->sendEmailVerificationNotification());
        }

        return response()->json([
            'message' => 'If that account exists and is not yet verified, a new verification link has been sent.',
        ], 202);
    }

    private function redirectToFrontend(string $status): RedirectResponse
    {
        return redirect()->away(config('helpdesk.frontend_url').'/verify-email?status='.$status);
    }
}
