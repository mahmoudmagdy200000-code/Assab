<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Published because the framework default covers only ['api/*',
    | 'sanctum/csrf-cookie'] — leaving 'broadcasting/*' uncovered, so Pusher
    | auth failed cross-origin while every other call worked.
    |
    */

    'paths' => ['api/*', 'broadcasting/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    /*
     | Driven by env so staging/production can pin the dashboard origin without
     | a code change. Defaults to the FRONTEND_URL the notifications already
     | build their links from; '*' only when neither is set (local dev).
     */
    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', (string) env('FRONTEND_URL', '*'))),
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    /*
     | Exposed so the frontend can read the idempotency echo and correlate a
     | retry with its original request.
     */
    'exposed_headers' => ['Idempotency-Key'],

    'max_age' => 86400,

    /*
     | Auth is Bearer-token (Sanctum personal access tokens), which needs no
     | cookies. The browser also refuses credentialed requests against a
     | wildcard origin, so leaving this false keeps the '*' fallback usable.
     */
    'supports_credentials' => false,

];
