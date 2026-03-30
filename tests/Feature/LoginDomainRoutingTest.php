<?php

use App\Models\User;
use App\Support\RouteUrls;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

beforeEach(function (): void {
    putenv('AUTH_LOGIN_DOMAIN=login.platebook.dk');
    $_ENV['AUTH_LOGIN_DOMAIN'] = 'login.platebook.dk';
    $_SERVER['AUTH_LOGIN_DOMAIN'] = 'login.platebook.dk';

    $this->refreshApplication();
    $this->artisan('migrate:fresh', ['--force' => true]);
});

afterEach(function (): void {
    putenv('AUTH_LOGIN_DOMAIN');
    unset($_ENV['AUTH_LOGIN_DOMAIN'], $_SERVER['AUTH_LOGIN_DOMAIN']);

    $this->refreshApplication();
});

test('login routes move to the dedicated login domain when configured', function () {
    expect(parse_url(route('login'), PHP_URL_HOST))->toBe('login.platebook.dk');
    expect(parse_url(route('login.store'), PHP_URL_HOST))->toBe('login.platebook.dk');
    expect(parse_url(route('login'), PHP_URL_PATH) ?: '/')->toBe('/');
    expect(parse_url(route('login.store'), PHP_URL_PATH))->toBe('/login');
});

test('legacy login path redirects to the dedicated login domain', function () {
    $response = $this->get('/login');

    $response->assertRedirect(route('login'));
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

test('app route helper keeps platform links on the main app host', function () {
    config(['app.url' => 'https://platebook.dk']);

    $request = Request::create('https://login.platebook.dk', 'GET');
    $this->app['url']->setRequest($request);

    expect(RouteUrls::appHome())->toBe('https://platebook.dk');
    expect(RouteUrls::app('platform.login'))->toBe('https://platebook.dk/platform/login');
});
