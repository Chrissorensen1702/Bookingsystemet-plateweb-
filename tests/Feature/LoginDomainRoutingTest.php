<?php

use App\Models\PlatformUser;
use App\Models\User;
use App\Support\RouteUrls;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

beforeEach(function (): void {
    putenv('APP_URL=https://platebook.dk');
    $_ENV['APP_URL'] = 'https://platebook.dk';
    $_SERVER['APP_URL'] = 'https://platebook.dk';
    putenv('AUTH_LOGIN_DOMAIN=login.platebook.dk');
    $_ENV['AUTH_LOGIN_DOMAIN'] = 'login.platebook.dk';
    $_SERVER['AUTH_LOGIN_DOMAIN'] = 'login.platebook.dk';
    putenv('SESSION_DOMAIN=.platebook.dk');
    $_ENV['SESSION_DOMAIN'] = '.platebook.dk';
    $_SERVER['SESSION_DOMAIN'] = '.platebook.dk';

    $this->refreshApplication();
    config([
        'app.url' => 'https://platebook.dk',
        'security.auth.login_domain' => 'login.platebook.dk',
        'security.domains.public_root' => 'platebook.dk',
        'session.domain' => '.platebook.dk',
    ]);
    $this->artisan('migrate:fresh', ['--force' => true]);
});

afterEach(function (): void {
    putenv('APP_URL');
    putenv('AUTH_LOGIN_DOMAIN');
    putenv('SESSION_DOMAIN');
    unset(
        $_ENV['APP_URL'],
        $_SERVER['APP_URL'],
        $_ENV['AUTH_LOGIN_DOMAIN'],
        $_SERVER['AUTH_LOGIN_DOMAIN'],
        $_ENV['SESSION_DOMAIN'],
        $_SERVER['SESSION_DOMAIN']
    );

    $this->refreshApplication();
});

test('login routes move to the dedicated login domain when configured', function () {
    expect(parse_url(route('login'), PHP_URL_HOST))->toBe('login.platebook.dk');
    expect(parse_url(route('login.store'), PHP_URL_HOST))->toBe('login.platebook.dk');
    expect(parse_url(route('login'), PHP_URL_PATH) ?: '/')->toBe('/');
    expect(parse_url(route('login.store'), PHP_URL_PATH))->toBe('/login');
});

test('legacy login path redirects to the dedicated login domain', function () {
    $response = $this->get('https://platebook.dk/login');

    $response->assertRedirect(route('login'));
});

test('login domain login path redirects to the canonical login page', function () {
    $response = $this->get('https://login.platebook.dk/login');

    $response->assertRedirect('https://login.platebook.dk');
});

test('login page uses a versioned service worker url to bypass stale edge caches', function () {
    $response = $this->get('https://login.platebook.dk/');
    $serviceWorkerVersion = @filemtime(public_path('sw.js'));

    $response->assertOk();
    $response->assertSee('meta name="pwa-sw-url" content="', false);
    $response->assertSee('sw.js?v='.$serviceWorkerVersion.'"', false);
});

test('employee login page marks auth submission for native redirect handling', function () {
    $response = $this->get('https://login.platebook.dk/');

    $response->assertOk();
    $response->assertSee('action="https://login.platebook.dk/login"', false);
    $response->assertSee('data-csrf-submit-mode="native"', false);
    $response->assertSee('data-auth-state-url="https://login.platebook.dk/auth-state"', false);
    $response->assertSee('data-auth-state-goal="authenticated"', false);
});

test('csrf token endpoint is available on the login domain', function () {
    $response = $this->get('https://login.platebook.dk/csrf-token');

    $response->assertOk();
    $response->assertJsonStructure(['token']);

    $cacheControl = (string) $response->headers->get('Cache-Control');

    expect($cacheControl)->toContain('no-store');
    expect($cacheControl)->toContain('no-cache');
    expect($cacheControl)->toContain('must-revalidate');
    expect($cacheControl)->toContain('max-age=0');
});

test('auth state endpoint is available on the login domain', function () {
    $response = $this->get('https://login.platebook.dk/auth-state');

    $response->assertOk();
    $response->assertJson([
        'guard' => 'web',
        'authenticated' => false,
    ]);

    expect(parse_url((string) $response->json('redirect'), PHP_URL_HOST))->toBe('login.platebook.dk');
});

test('login domain responses disable caching and clear legacy host-only auth cookies', function () {
    $response = $this->get('https://login.platebook.dk/');

    $response->assertOk();
    $response->assertHeader('Clear-Site-Data', '"cache", "storage"');

    $cacheControl = (string) $response->headers->get('Cache-Control');

    expect($cacheControl)->toContain('no-store');
    expect($cacheControl)->toContain('no-cache');
    expect($cacheControl)->toContain('must-revalidate');
    expect($cacheControl)->toContain('max-age=0');

    $cookies = collect($response->headers->getCookies());
    $legacySessionCookie = Str::slug((string) config('app.name', 'laravel')).'-session';

    expect($cookies->contains(function ($cookie) use ($legacySessionCookie): bool {
        return $cookie->getName() === $legacySessionCookie
            && $cookie->getDomain() === null
            && $cookie->isCleared();
    }))->toBeTrue();

    expect($cookies->contains(function ($cookie): bool {
        return $cookie->getName() === 'XSRF-TOKEN'
            && $cookie->getDomain() === null
            && $cookie->isCleared();
    }))->toBeTrue();
});

test('protected app routes redirect guests to the dedicated login domain', function () {
    config(['app.url' => 'https://platebook.dk']);

    $response = $this->get('https://platebook.dk/');

    $response->assertRedirect('https://login.platebook.dk');
});

test('protected platform routes redirect guests to the platform login on the main app host', function () {
    config(['app.url' => 'https://platebook.dk']);

    $response = $this->get('https://platebook.dk/platform');

    $response->assertRedirect('https://platebook.dk/platform/login');
});

test('successful login on the login domain redirects back to the main app url', function () {
    config(['app.url' => 'http://platebook.dk']);

    $user = User::factory()->create([
        'email' => 'medarbejder@example.com',
        'password' => Hash::make('SecurePass123!'),
    ]);

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'SecurePass123!',
    ]);

    $response->assertRedirect('http://platebook.dk');
});

test('successful login ignores stale intended urls on the login domain', function () {
    config(['app.url' => 'https://platebook.dk']);

    $user = User::factory()->create([
        'email' => 'medarbejder@example.com',
        'password' => Hash::make('SecurePass123!'),
    ]);

    $response = $this
        ->withSession(['url.intended' => 'https://login.platebook.dk/login'])
        ->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'SecurePass123!',
        ]);

    $response->assertRedirect('https://platebook.dk');
});

test('successful login still respects intended app urls', function () {
    config(['app.url' => 'https://platebook.dk']);

    $user = User::factory()->create([
        'email' => 'medarbejder@example.com',
        'password' => Hash::make('SecurePass123!'),
    ]);

    $response = $this
        ->withSession(['url.intended' => 'https://platebook.dk/profil'])
        ->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'SecurePass123!',
        ]);

    $response->assertRedirect('https://platebook.dk/profil');
});

test('app route helper keeps platform links on the main app host', function () {
    config(['app.url' => 'https://platebook.dk']);

    $request = Request::create('https://login.platebook.dk', 'GET');
    $this->app['url']->setRequest($request);

    expect(RouteUrls::appHome())->toBe('https://platebook.dk');
    expect(RouteUrls::app('platform.login'))->toBe('https://platebook.dk/platform/login');
    expect(RouteUrls::platform('dashboard'))->toBe('https://platebook.dk/platform');
    expect(RouteUrls::loginHome())->toBe('https://login.platebook.dk');
});

test('non-login pages on the login domain redirect back to the main app host', function () {
    config(['app.url' => 'https://platebook.dk']);

    $response = $this->get('https://login.platebook.dk/book-tid?tenant=demo');

    $response->assertRedirect('https://platebook.dk/book-tid?tenant=demo');
});

test('platform login page keeps its internal urls on the main app host', function () {
    config(['app.url' => 'https://platebook.dk']);

    $response = $this->get('https://support.example.test/platform/login');

    $response->assertOk();
    $response->assertSee('href="https://platebook.dk/platform/login"', false);
    $response->assertSee('action="https://platebook.dk/platform/login"', false);
    $response->assertSee('data-csrf-submit-mode="native"', false);
    $response->assertSee('data-auth-state-guard="platform"', false);
});

test('authenticated verification notice marks logout submission for native redirect handling', function () {
    config(['app.url' => 'https://platebook.dk']);

    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get('https://platebook.dk/email/verify');

    $response->assertOk();
    $response->assertSee('action="https://platebook.dk/logout"', false);
    $response->assertSee('data-csrf-submit-mode="native"', false);
    $response->assertSee('data-auth-state-goal="guest"', false);
});

test('authenticated platform dashboard marks logout submission for native redirect handling', function () {
    config(['app.url' => 'https://platebook.dk']);

    $platformUser = PlatformUser::query()->create([
        'name' => 'Platform Dev',
        'email' => 'platform@example.com',
        'password' => Hash::make('SecurePass123!'),
        'role' => PlatformUser::ROLE_DEVELOPER,
        'is_active' => true,
    ]);

    $response = $this
        ->actingAs($platformUser, 'platform')
        ->get('https://platebook.dk/platform');

    $response->assertOk();
    $response->assertSee('action="https://platebook.dk/platform/logout"', false);
    $response->assertSee('data-csrf-submit-mode="native"', false);
    $response->assertSee('data-auth-state-goal="guest"', false);
});
