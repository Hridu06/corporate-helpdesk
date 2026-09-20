<?php

return [

    /*
    | Public URL of the React SPA. Used for CORS and for redirects after
    | email verification.
    */
    'frontend_url' => rtrim(env('FRONTEND_URL', 'http://localhost:5173'), '/'),

    /*
    | Bearer token lifetimes in minutes. "Remember me" issues a longer-lived
    | token; Sanctum's global `expiration` remains the hard upper bound.
    */
    'token_ttl' => (int) env('AUTH_TOKEN_TTL', 720),
    'remember_token_ttl' => (int) env('AUTH_REMEMBER_TOKEN_TTL', 43200),

    /*
    | How long sign-in history (IP addresses, devices) is kept, in days.
    | Older rows are deleted daily by `helpdesk:prune-login-activities`.
    */
    'login_activity_retention_days' => (int) env('LOGIN_ACTIVITY_RETENTION_DAYS', 90),

];
