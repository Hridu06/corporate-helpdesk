<?php

/*
| The API is called by the React app with a Bearer token (no cookies), so credentials
| are not enabled. Origins, methods and headers are listed explicitly rather than "*".
| FRONTEND_URL is always allowed; CORS_ALLOWED_ORIGINS adds more (comma-separated).
*/
$origins = array_merge(
    [env('FRONTEND_URL', 'http://localhost:5173')],
    explode(',', (string) env('CORS_ALLOWED_ORIGINS', '')),
);

return [

    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_values(array_unique(array_filter(array_map(fn ($o) => rtrim(trim((string) $o), '/'), $origins)))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Accept', 'Authorization', 'Content-Type', 'X-Requested-With'],

    'exposed_headers' => [],

    // Browsers may cache a preflight answer for this many seconds.
    'max_age' => 600,

    'supports_credentials' => false,

];
