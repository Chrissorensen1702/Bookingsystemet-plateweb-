<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AddSecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! (bool) config('security.headers.enabled', true)) {
            return $response;
        }

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $response->headers->set('Cross-Origin-Resource-Policy', 'same-origin');
        $response->headers->set('X-Permitted-Cross-Domain-Policies', 'none');

        $contentSecurityPolicy = $this->buildContentSecurityPolicy();

        if ($contentSecurityPolicy !== '') {
            $response->headers->set('Content-Security-Policy', $contentSecurityPolicy);
        }

        if ((bool) config('security.headers.hsts', false) && $request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');
        }

        return $response;
    }

    private function buildContentSecurityPolicy(): string
    {
        if (! (bool) config('security.headers.csp.enabled', true)) {
            return '';
        }

        $scriptSources = ["'self'", "'unsafe-inline'"];
        $styleSources = ["'self'", "'unsafe-inline'"];
        $connectSources = ["'self'"];

        if (app()->environment('local')) {
            $scriptSources = array_merge($scriptSources, [
                'http://localhost:*',
                'http://127.0.0.1:*',
                'http://[::1]:*',
            ]);
            $styleSources = array_merge($styleSources, [
                'http://localhost:*',
                'http://127.0.0.1:*',
                'http://[::1]:*',
            ]);
            $connectSources = array_merge($connectSources, [
                'http://localhost:*',
                'http://127.0.0.1:*',
                'http://[::1]:*',
                'ws://localhost:*',
                'ws://127.0.0.1:*',
                'ws://[::1]:*',
                'wss://localhost:*',
                'wss://127.0.0.1:*',
                'wss://[::1]:*',
            ]);
        }

        $directives = [
            "default-src 'self'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
            "object-src 'none'",
            'script-src ' . implode(' ', array_unique($scriptSources)),
            'style-src ' . implode(' ', array_unique($styleSources)),
            "img-src 'self' data: blob: https:",
            "font-src 'self' data: https:",
            "connect-src 'self' " . implode(' ', array_filter(array_unique($connectSources), static fn (string $value): bool => $value !== "'self'")),
            "media-src 'self' blob:",
            "worker-src 'self' blob:",
        ];

        return implode('; ', $directives) . ';';
    }
}
