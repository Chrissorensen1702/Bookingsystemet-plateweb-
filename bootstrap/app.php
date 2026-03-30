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

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
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
        //
    })->create();
