<?php

$secureDefaultsEnabled = ! in_array((string) env('APP_ENV', 'production'), ['local', 'testing'], true);

return [
    'auth' => [
        // Default to verified-email logins outside local/testing.
        'require_verified_email' => (bool) env('AUTH_REQUIRE_VERIFIED_EMAIL', $secureDefaultsEnabled),
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
        // Check passwords against known leaked-password data outside local/testing by default.
        'require_uncompromised' => (bool) env('PASSWORD_REQUIRE_UNCOMPROMISED', $secureDefaultsEnabled),
    ],

    'headers' => [
        'enabled' => (bool) env('SECURITY_HEADERS_ENABLED', true),
        // HSTS is only emitted on secure requests, so it is safe to default it on outside local/testing.
        'hsts' => (bool) env('SECURITY_HSTS_ENABLED', $secureDefaultsEnabled),
        'csp' => [
            'enabled' => (bool) env('SECURITY_CSP_ENABLED', true),
        ],
    ],
];
