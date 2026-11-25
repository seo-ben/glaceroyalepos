<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You may adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_unique(array_filter(array_map('trim', array_merge(
        explode(',', env('FRONTEND_URL', 'https://glaceroyalpos.net,https://www.glaceroyalpos.net,https://omegapos.netlify.app,http://localhost:5173,http://127.0.0.1:5173')),
        [
            'https://glaceroyalpos.net',
            'https://www.glaceroyalpos.net',
        ]
    ))))),

    'allowed_origins_patterns' => [
        '/^https:\/\/([a-z0-9-]+\.)?glaceroyalpos\.net$/',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
