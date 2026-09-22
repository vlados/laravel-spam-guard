<?php

return [
    'api_key' => env('TYPESAFE_API_KEY'),
    'context' => 'Public contact form',
    'threshold' => 0.9,
    'fail_open' => true,
    'model' => 'jev-1.13.0',
    'timeout' => 2.0,
    'connect_timeout' => 1.0,
    'max_state_chars' => 10_000,
    'turnstile' => [
        'site_key' => env('TURNSTILE_SITE_KEY'),
        'secret_key' => env('TURNSTILE_SECRET_KEY'),
        'hostname' => env('TURNSTILE_HOSTNAME'),
        'timeout' => 5.0,
        'connect_timeout' => 2.0,
    ],
];
