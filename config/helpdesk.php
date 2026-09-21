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

    /*
    | Ticket attachments. Files live on the private `attachments` disk and are only
    | ever served through the authorised download endpoint. An extension is accepted
    | only if the file's detected content type is one of the listed MIME types.
    | The PHP upload limits (upload_max_filesize / post_max_size) must be at least
    | as large as max_size_kb / max_request_kb.
    */
    'attachments' => [
        'disk' => 'attachments',
        'max_size_kb' => (int) env('ATTACHMENT_MAX_SIZE_KB', 5120),
        'max_files' => (int) env('ATTACHMENT_MAX_FILES', 5),
        'max_request_kb' => (int) env('ATTACHMENT_MAX_REQUEST_KB', 15360),
        'max_ticket_files' => (int) env('ATTACHMENT_MAX_TICKET_FILES', 25),
        'max_ticket_kb' => (int) env('ATTACHMENT_MAX_TICKET_KB', 51200),
        // Refuse images larger than this many pixels (decompression-bomb guard).
        'max_image_pixels' => 40_000_000,
        // Refuse Office files that unpack to more than this many bytes (zip-bomb guard).
        'max_unpacked_bytes' => 100_000_000,
        'types' => [
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png' => ['image/png'],
            'webp' => ['image/webp'],
            'gif' => ['image/gif'],
            'pdf' => ['application/pdf'],
            'txt' => ['text/plain'],
            'csv' => ['text/plain', 'text/csv', 'application/csv'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
            'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        ],
    ],

    /*
    | Reverse proxies / load balancers whose X-Forwarded-* headers are believed: empty
    | (default) trusts none, "*" trusts every caller (only safe when the app is reachable
    | solely through the proxy), otherwise a comma-separated list of IPs or CIDR ranges.
    | Without this behind a proxy, every client looks like the proxy's IP and the per-IP
    | rate limits (login, registration, password reset) would all share one bucket.
    */
    'trusted_proxies' => env('TRUSTED_PROXIES'),

    /*
    | Emails about ticket activity (opened, assigned, replied, resolved, ...). They are queued:
    | production needs a running `php artisan queue:work`; QUEUE_CONNECTION=sync sends them at
    | once (development, tests). Set to false to switch every ticket email off.
    */
    'ticket_notifications' => (bool) env('TICKET_NOTIFICATIONS_ENABLED', true),

    /*
    | Extra origins allowed by CORS besides FRONTEND_URL (comma-separated, no trailing slash).
    */
    'cors_extra_origins' => env('CORS_ALLOWED_ORIGINS'),

    /*
    | Send Strict-Transport-Security on HTTPS responses. Enable once the site is served
    | over HTTPS only: browsers then refuse plain HTTP for the whole period.
    */
    'hsts' => (bool) env('HSTS_ENABLED', false),
    'hsts_max_age' => (int) env('HSTS_MAX_AGE', 31536000),

    /* The scheduler writes this cache key every minute; `helpdesk:preflight` checks it is fresh. */
    'scheduler_heartbeat_key' => 'helpdesk.scheduler.heartbeat',

];
