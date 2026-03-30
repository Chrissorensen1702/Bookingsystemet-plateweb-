<?php

use App\Http\Middleware\UseAppOriginForInternalRoutes;
use App\Support\RouteUrls;
use Illuminate\Http\Request;
use Illuminate\Routing\UrlGenerator;

beforeEach(function (): void {
    putenv('APP_URL=http://localhost/bookingsystem/public');
    $_ENV['APP_URL'] = 'http://localhost/bookingsystem/public';
    $_SERVER['APP_URL'] = 'http://localhost/bookingsystem/public';

    $this->refreshApplication();
    config([
        'app.url' => 'http://localhost/bookingsystem/public',
        'security.auth.login_domain' => null,
    ]);
});

afterEach(function (): void {
    putenv('APP_URL');
    unset($_ENV['APP_URL'], $_SERVER['APP_URL']);

    $this->refreshApplication();
});

test('app request keeps the configured base path for local subdirectory installs', function () {
    $request = Request::create('http://localhost/login?preview=1', 'GET');

    expect(RouteUrls::appRequest($request))->toBe('http://localhost/bookingsystem/public/login?preview=1');
});

test('internal route generation keeps the configured base path for local subdirectory installs', function () {
    $request = Request::create('http://localhost/login', 'GET');
    $this->app['url']->setRequest($request);

    $middleware = app(UseAppOriginForInternalRoutes::class);
    $middleware->handle($request, static fn () => response('ok'));

    expect(route('login.store'))->toBe('http://localhost/bookingsystem/public/login');
    expect(route('platform.login'))->toBe('http://localhost/bookingsystem/public/platform/login');
    expect(route('auth.state'))->toBe('http://localhost/bookingsystem/public/auth-state');
});

test('asset origin keeps the configured base path for local subdirectory installs', function () {
    app(UrlGenerator::class)->useAssetOrigin(rtrim((string) config('app.url'), '/'));

    expect(asset('images/logo/header.svg'))->toBe('http://localhost/bookingsystem/public/images/logo/header.svg');
});
