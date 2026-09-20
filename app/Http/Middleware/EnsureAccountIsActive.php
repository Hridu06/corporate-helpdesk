<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects authenticated requests from deactivated accounts, so a token issued
 * before deactivation stops working immediately.
 */
class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->isActive()) {
            return response()->json([
                'message' => 'Your account has been deactivated. Please contact support.',
                'code' => 'account_inactive',
            ], 403);
        }

        return $next($request);
    }
}
