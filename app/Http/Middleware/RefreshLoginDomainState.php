<?php

namespace App\Http\Middleware;

use App\Support\RouteUrls;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

class RefreshLoginDomainState
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $requestHost = mb_strtolower($request->getHost());
        $loginHost = mb_strtolower(RouteUrls::loginHost());
        $appHost = mb_strtolower(RouteUrls::appHost());

        if (! in_array($requestHost, array_filter([$loginHost, $appHost]), true)) {
            return $response;
        }

        if ($this->shouldDisableCaching($response)) {
            $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
            $response->headers->set('Pragma', 'no-cache');
            $response->headers->set('Expires', '0');
        }

        if ($requestHost === $loginHost) {
            $response->headers->set('Clear-Site-Data', '"cache", "storage"');
        }

        foreach ($this->legacySessionCookieNames() as $cookieName) {
            $response->headers->setCookie($this->expiredCookie($cookieName, $request, true));
            $response->headers->setCookie($this->expiredCookie($cookieName, $request, true, $request->getHost()));
        }

        $response->headers->setCookie($this->expiredCookie('XSRF-TOKEN', $request, false));
        $response->headers->setCookie($this->expiredCookie('XSRF-TOKEN', $request, false, $request->getHost()));

        return $response;
    }

    private function shouldDisableCaching(Response $response): bool
    {
        $contentType = mb_strtolower((string) $response->headers->get('Content-Type', ''));

        return $response->isRedirection() || str_contains($contentType, 'text/html');
    }

    /**
     * @return list<string>
     */
    private function legacySessionCookieNames(): array
    {
        $cookieNames = [
            Str::slug((string) config('app.name', 'laravel')).'-session',
            'laravel_session',
        ];

        return array_values(array_unique(array_filter($cookieNames)));
    }

    private function expiredCookie(string $name, Request $request, bool $httpOnly, ?string $domain = null): Cookie
    {
        return Cookie::create(
            $name,
            '',
            now()->subYear(),
            '/',
            $domain,
            $request->isSecure(),
            $httpOnly,
            false,
            (string) config('session.same_site', 'lax')
        );
    }
}
