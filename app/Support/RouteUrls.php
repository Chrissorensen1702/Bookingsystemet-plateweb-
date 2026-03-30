<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Routing\UrlGenerator;

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

    public static function appRequest(Request $request): string
    {
        $generator = clone app(UrlGenerator::class);
        $generator->useOrigin((string) config('app.url'));

        $path = '/'.ltrim($request->getPathInfo(), '/');
        $query = $request->getQueryString();

        return $generator->to($path).($query !== null && $query !== '' ? '?'.$query : '');
    }
}
