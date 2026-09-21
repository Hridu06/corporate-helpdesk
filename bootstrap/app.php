<?php

use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Global (not the api group) so answers produced by earlier middleware, e.g. a 401, get the headers too.
        $middleware->append(SecurityHeaders::class);

        $middleware->alias([
            'active' => EnsureAccountIsActive::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // A body over PHP's post_max_size / the web server limit: answer with a clear API error.
        $exceptions->render(fn (PostTooLargeException $e, Request $request) => $request->is('api/*')
            ? response()->json(['message' => 'The upload is too large.', 'code' => 'payload_too_large'], 413)
            : null);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
