<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class RouteUrls
{
    public static function app(string $name, array $parameters = [], bool $absolute = true): string
    {
        if (! $absolute) {
            return route($name, $parameters, false);
        }

        $generator = clone app(UrlGenerator::class);
        $generator->useOrigin((string) config('app.url'));

        return $generator->route($name, $parameters, true);
    }

    public static function appHome(): string
    {
        $appUrl = trim((string) config('app.url', '/'));

        return $appUrl !== ''
            ? rtrim($appUrl, '/')
            : '/';
    }

    public static function appOrigin(): ?string
    {
        $appUrl = trim((string) config('app.url', ''));

        if ($appUrl === '') {
            return null;
        }

        $scheme = parse_url($appUrl, PHP_URL_SCHEME);
        $host = parse_url($appUrl, PHP_URL_HOST);
        $port = parse_url($appUrl, PHP_URL_PORT);

        if (! is_string($scheme) || ! is_string($host) || $scheme === '' || $host === '') {
            return null;
        }

        return $scheme . '://' . $host . ($port ? ':' . $port : '');
    }

    public static function appHost(): string
    {
        return trim((string) (parse_url((string) config('app.url', ''), PHP_URL_HOST) ?: ''));
    }

    public static function appRequest(Request $request): string
    {
        $generator = clone app(UrlGenerator::class);
        $generator->useOrigin((string) config('app.url'));

        $path = '/'.ltrim($request->getPathInfo(), '/');
        $query = $request->getQueryString();

        return $generator->to($path).($query !== null && $query !== '' ? '?'.$query : '');
    }

    public static function publicRootDomain(): string
    {
        $configured = trim((string) config('security.domains.public_root', ''));

        if ($configured !== '') {
            return $configured;
        }

        return self::appHost();
    }

    /**
     * @return list<string>
     */
    public static function reservedPublicSubdomains(): array
    {
        return collect(Arr::wrap(config('security.domains.reserved_public_subdomains', [])))
            ->map(static fn (mixed $value): string => mb_strtolower(trim((string) $value)))
            ->filter(static fn (string $value): bool => $value !== '')
            ->unique()
            ->values()
            ->all();
    }

    public static function isReservedPublicSubdomain(?string $value): bool
    {
        $candidate = mb_strtolower(trim((string) $value));

        return $candidate !== ''
            && in_array($candidate, self::reservedPublicSubdomains(), true);
    }

    /**
     * @return list<string>
     */
    public static function reservedPublicLocationSlugs(): array
    {
        return collect(Arr::wrap(config('security.domains.reserved_public_location_slugs', [])))
            ->map(static fn (mixed $value): string => mb_strtolower(trim((string) $value)))
            ->filter(static fn (string $value): bool => $value !== '')
            ->unique()
            ->values()
            ->all();
    }

    public static function isReservedPublicLocationSlug(?string $value): bool
    {
        $candidate = mb_strtolower(trim((string) $value));

        return $candidate !== ''
            && in_array($candidate, self::reservedPublicLocationSlugs(), true);
    }

    public static function isPublicTenantHost(?string $host): bool
    {
        $candidate = mb_strtolower(trim((string) $host));
        $rootDomain = mb_strtolower(trim(self::publicRootDomain()));
        $loginDomain = mb_strtolower(trim((string) config('security.auth.login_domain', '')));

        if ($candidate === '' || $rootDomain === '' || $candidate === $rootDomain) {
            return false;
        }

        if ($loginDomain !== '' && $candidate === $loginDomain) {
            return false;
        }

        return Str::endsWith($candidate, '.' . $rootDomain);
    }

    public static function publicBooking(string $tenantSlug, ?string $locationSlug = null, array $query = []): string
    {
        $routeName = filled($locationSlug)
            ? 'public-booking.create'
            : 'public-booking.tenant';

        $parameters = [
            'tenantSlug' => $tenantSlug,
        ];

        if (filled($locationSlug)) {
            $parameters['locationSlug'] = $locationSlug;
        }

        $url = route($routeName, $parameters);
        $query = array_filter(
            $query,
            static fn (mixed $value): bool => $value !== null && $value !== ''
        );

        if ($query === []) {
            return $url;
        }

        return $url . (str_contains($url, '?') ? '&' : '?') . Arr::query($query);
    }
}
