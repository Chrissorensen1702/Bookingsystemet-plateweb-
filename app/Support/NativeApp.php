<?php

namespace App\Support;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

class NativeApp
{
    public const QUERY_KEY = 'native_app';
    public const LOCK_COOKIE = 'platebook_native_lock';

    public static function syncSession(Request $request): bool
    {
        $nativeApp = $request->boolean(self::QUERY_KEY) || $request->session()->boolean(self::QUERY_KEY);

        if ($nativeApp) {
            $request->session()->put(self::QUERY_KEY, true);
        }

        return $nativeApp;
    }

    public static function isEnabled(Request $request): bool
    {
        return $request->boolean(self::QUERY_KEY) || $request->session()->boolean(self::QUERY_KEY);
    }

    /**
     * @return array<string, int>
     */
    public static function query(): array
    {
        return [
            self::QUERY_KEY => 1,
        ];
    }

    public static function lockCookie(Request $request): Cookie
    {
        return cookie(
            self::LOCK_COOKIE,
            '1',
            60 * 24 * 180,
            '/',
            self::cookieDomain(),
            self::isSecure($request),
            true,
            false,
            'lax'
        );
    }

    public static function forgetLockCookie(Request $request): Cookie
    {
        return cookie()->forget(self::LOCK_COOKIE, '/', self::cookieDomain());
    }

    private static function cookieDomain(): ?string
    {
        $configuredDomain = trim((string) config('session.domain', ''));

        if ($configuredDomain !== '') {
            return $configuredDomain;
        }

        $publicRootDomain = trim(RouteUrls::publicRootDomain());

        if (
            $publicRootDomain === ''
            || $publicRootDomain === 'localhost'
            || filter_var($publicRootDomain, FILTER_VALIDATE_IP) !== false
        ) {
            return null;
        }

        return '.'.$publicRootDomain;
    }

    private static function isSecure(Request $request): bool
    {
        if ($request->isSecure()) {
            return true;
        }

        $appUrl = trim((string) config('app.url', ''));

        return str_starts_with($appUrl, 'https://');
    }
}
