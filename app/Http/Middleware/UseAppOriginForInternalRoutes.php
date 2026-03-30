<?php

namespace App\Http\Middleware;

use App\Support\RouteUrls;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\UrlGenerator;
use Symfony\Component\HttpFoundation\Response;

class UseAppOriginForInternalRoutes
{
    public function handle(Request $request, Closure $next): Response
    {
        $appBaseUrl = RouteUrls::appBaseUrl();

        if (
            $appBaseUrl !== null
            && ! RouteUrls::isPublicTenantHost($request->getHost())
            && mb_strtolower($request->getHost()) !== mb_strtolower(RouteUrls::loginHost())
        ) {
            app(UrlGenerator::class)->useOrigin($appBaseUrl);
        }

        return $next($request);
    }
}
