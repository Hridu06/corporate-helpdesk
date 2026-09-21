<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline hardening headers for API responses (paths under /api). A header a controller already set
 * (the attachment download sets its own Content-Security-Policy) is left alone.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $request->is('api/*')) {
            return $response;
        }

        $defaults = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'no-referrer',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
        ];

        if (config('helpdesk.hsts') && $request->isSecure()) {
            $defaults['Strict-Transport-Security'] = 'max-age='.config('helpdesk.hsts_max_age');
        }

        foreach ($defaults as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        // Every API answer belongs to one authenticated person: no browser or proxy keeps a copy.
        if (! str_contains((string) $response->headers->get('Cache-Control'), 'no-store')) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }
}
