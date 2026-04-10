<?php

namespace App\Support;

use Illuminate\Http\Request;

class AuthRedirects
{
    public static function resolvePostLoginRedirect(Request $request): string
    {
        $fallback = RouteUrls::appHome();
        $intended = $request->session()->pull('url.intended');

        if (! is_string($intended) || trim($intended) === '') {
            return $fallback;
        }

        $intendedHost = mb_strtolower(trim((string) (parse_url($intended, PHP_URL_HOST) ?: '')));
        $intendedPath = '/'.ltrim((string) (parse_url($intended, PHP_URL_PATH) ?: '/'), '/');
        $loginHost = mb_strtolower(RouteUrls::loginHost());
        $appHost = mb_strtolower(RouteUrls::appHost());

        if ($loginHost !== '' && $intendedHost === $loginHost) {
            return $fallback;
        }

        if ($intendedPath === '/login') {
            return $fallback;
        }

        if ($intendedHost !== '' && $appHost !== '' && $intendedHost !== $appHost) {
            return $fallback;
        }

        return $intended;
    }
}
