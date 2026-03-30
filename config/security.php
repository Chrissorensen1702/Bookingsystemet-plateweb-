<?php

return [
    'auth' => [
        // Temporarily disable in environments where mail delivery is not configured.
        'require_verified_email' => (bool) env('AUTH_REQUIRE_VERIFIED_EMAIL', false),
        // Optional dedicated host for the employee login, e.g. "login.platebook.dk".
        'login_domain' => env('AUTH_LOGIN_DOMAIN'),
    ],

    'domains' => [
        // Root domain used for tenant public booking subdomains, e.g. "platebook.dk".
        'public_root' => env(
            'PUBLIC_ROOT_DOMAIN',
            parse_url((string) env('APP_URL', 'http://localhost'), PHP_URL_HOST) ?: 'localhost'
        ),
        'reserved_public_subdomains' => array_values(array_filter(array_map(
            static fn (string $value): string => trim($value),
            explode(
                ',',
                (string) env('RESERVED_PUBLIC_SUBDOMAINS', 'login,www,app,api,platform')
            )
        ))),
        'reserved_public_location_slugs' => array_values(array_filter(array_map(
            static fn (string $value): string => trim($value),
            explode(
                ',',
                (string) env(
                    'RESERVED_PUBLIC_LOCATION_SLUGS',
                    'login,platform,email,mine-vagter,ydelser,tilgaengelighed,indstillinger,profil,brugere,book-tid,up'
                )
            )
        ))),
    ],

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
