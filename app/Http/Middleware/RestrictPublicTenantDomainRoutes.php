<?php

namespace App\Http\Middleware;

use App\Support\RouteUrls;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RestrictPublicTenantDomainRoutes
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! RouteUrls::isPublicTenantHost($request->getHost())) {
            return $next($request);
        }

        if (
            $request->routeIs('public-booking.create', 'public-booking.store', 'public-booking.time-options')
            && RouteUrls::isReservedPublicLocationSlug((string) $request->route('locationSlug'))
        ) {
            return redirect()->to(RouteUrls::appRequest($request), 302);
        }

        if ($request->routeIs('public-booking.*', 'csrf.token')) {
            return $next($request);
        }

        return redirect()->to(RouteUrls::appRequest($request), 302);
    }
}
