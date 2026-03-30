<?php

namespace App\Http\Middleware;

use App\Support\RouteUrls;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

class RefreshLoginDomainState
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $loginHost = mb_strtolower(RouteUrls::loginHost());

        if ($loginHost === '' || mb_strtolower($request->getHost()) !== $loginHost) {
            return $response;
        }

        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');
        $response->headers->set('Clear-Site-Data', '"cache", "storage"');

        // Remove any older host-only auth cookies that were created before we
        // standardized on a shared parent-domain session cookie.
        $response->headers->setCookie($this->expiredHostOnlyCookie(
            (string) config('session.cookie'),
            $request,
            true
        ));
        $response->headers->setCookie($this->expiredHostOnlyCookie(
            'XSRF-TOKEN',
            $request,
            false
        ));

        return $response;
    }

    private function expiredHostOnlyCookie(string $name, Request $request, bool $httpOnly): Cookie
    {
        return Cookie::create(
            $name,
            '',
            now()->subYear(),
            '/',
            null,
            $request->isSecure(),
            $httpOnly,
            false,
            (string) config('session.same_site', 'lax')
        );
    }
}
