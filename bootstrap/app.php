<?php

use App\Http\Middleware\AddSecurityHeaders;
use App\Http\Middleware\RefreshLoginDomainState;
use App\Http\Middleware\RestrictLoginDomainRoutes;
use App\Http\Middleware\RestrictPublicTenantDomainRoutes;
use App\Http\Middleware\UseAppOriginForInternalRoutes;
use App\Support\RouteUrls;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustHosts(
            at: static fn (): array => RouteUrls::trustedHostPatterns(),
            subdomains: false,
        );

        $trustedProxies = array_values(array_filter(array_map(
            static fn (string $value): string => trim($value),
            explode(',', (string) env('TRUSTED_PROXIES', ''))
        )));

        if ($trustedProxies !== []) {
            $middleware->trustProxies(at: $trustedProxies);
        }

        $middleware->redirectGuestsTo(function (Request $request): string {
            if ($request->is('platform') || $request->is('platform/*')) {
                return RouteUrls::platform('login');
            }

            return RouteUrls::loginHome();
        });

        $middleware->web(prepend: [
            RefreshLoginDomainState::class,
        ]);

        $middleware->web(append: [
            RestrictLoginDomainRoutes::class,
            RestrictPublicTenantDomainRoutes::class,
            UseAppOriginForInternalRoutes::class,
            AddSecurityHeaders::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->stopIgnoring(TokenMismatchException::class);

        $exceptions->report(function (TokenMismatchException $exception): void {
            $request = request();
            $sessionCookie = (string) config('session.cookie');

            logger()->warning('CSRF token mismatch', [
                'method' => $request->getMethod(),
                'host' => $request->getHost(),
                'path' => $request->path(),
                'route' => optional($request->route())->getName(),
                'referer' => $request->headers->get('referer'),
                'origin' => $request->headers->get('origin'),
                'user_agent' => $request->userAgent(),
                'session_driver' => config('session.driver'),
                'session_domain' => config('session.domain'),
                'session_cookie' => $sessionCookie,
                'has_session_cookie' => $sessionCookie !== '' && $request->cookies->has($sessionCookie),
                'has_xsrf_cookie' => $request->cookies->has('XSRF-TOKEN'),
                'request_cookie_names' => array_keys($request->cookies->all()),
                'has_form_token' => $request->request->has('_token'),
                'has_header_token' => $request->headers->has('X-CSRF-TOKEN'),
                'is_authenticated' => auth()->check(),
            ]);
        });
    })->create();
