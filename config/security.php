<?php

return [
    'password' => [
        // If true, passwords are checked against known leaked-password data.
        // Keep false in local/dev if you want faster validation without external checks.
        'require_uncompromised' => (bool) env('PASSWORD_REQUIRE_UNCOMPROMISED', false),
    ],

    'headers' => [
        'enabled' => (bool) env('SECURITY_HEADERS_ENABLED', true),
        // Enable HSTS only when the app is running behind HTTPS in production.
        'hsts' => (bool) env('SECURITY_HSTS_ENABLED', false),
        'csp' => [
            'enabled' => (bool) env('SECURITY_CSP_ENABLED', true),
        ],
    ],
];
