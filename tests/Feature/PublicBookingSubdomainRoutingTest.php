<?php

use App\Models\Location;
use App\Models\Tenant;

beforeEach(function (): void {
    putenv('APP_URL=https://platebook.dk');
    putenv('AUTH_LOGIN_DOMAIN=login.platebook.dk');
    putenv('PUBLIC_ROOT_DOMAIN=platebook.dk');

    $_ENV['APP_URL'] = 'https://platebook.dk';
    $_SERVER['APP_URL'] = 'https://platebook.dk';
    $_ENV['AUTH_LOGIN_DOMAIN'] = 'login.platebook.dk';
    $_SERVER['AUTH_LOGIN_DOMAIN'] = 'login.platebook.dk';
    $_ENV['PUBLIC_ROOT_DOMAIN'] = 'platebook.dk';
    $_SERVER['PUBLIC_ROOT_DOMAIN'] = 'platebook.dk';

    $this->refreshApplication();
    config([
        'app.url' => 'https://platebook.dk',
        'security.auth.login_domain' => 'login.platebook.dk',
        'security.domains.public_root' => 'platebook.dk',
    ]);
    $this->artisan('migrate:fresh', ['--force' => true]);
});

afterEach(function (): void {
    putenv('APP_URL');
    putenv('AUTH_LOGIN_DOMAIN');
    putenv('PUBLIC_ROOT_DOMAIN');

    unset(
        $_ENV['APP_URL'],
        $_SERVER['APP_URL'],
        $_ENV['AUTH_LOGIN_DOMAIN'],
        $_SERVER['AUTH_LOGIN_DOMAIN'],
        $_ENV['PUBLIC_ROOT_DOMAIN'],
        $_SERVER['PUBLIC_ROOT_DOMAIN'],
    );

    $this->refreshApplication();
});

function createPublicTenantWithLocation(string $tenantSlug, string $locationSlug, string $locationName = 'Bordingafdelingen'): array
{
    $tenant = Tenant::query()->create([
        'name' => 'Chris Virksomhed',
        'slug' => $tenantSlug,
        'timezone' => 'Europe/Copenhagen',
        'is_active' => true,
    ]);

    $location = Location::query()->create([
        'tenant_id' => $tenant->id,
        'name' => $locationName,
        'slug' => $locationSlug,
        'timezone' => 'Europe/Copenhagen',
        'is_active' => true,
    ]);

    return [$tenant, $location];
}

test('public booking routes use tenant subdomain and location slug', function () {
    [$tenant, $location] = createPublicTenantWithLocation('chrisvirksomhed', 'bordingafdelingen');

    expect(route('public-booking.tenant', ['tenantSlug' => $tenant->slug]))->toBe('https://chrisvirksomhed.platebook.dk');
    expect(route('public-booking.create', [
        'tenantSlug' => $tenant->slug,
        'locationSlug' => $location->slug,
    ]))->toBe('https://chrisvirksomhed.platebook.dk/bordingafdelingen');
});

test('canonical public booking page renders on tenant subdomain', function () {
    [$tenant, $location] = createPublicTenantWithLocation('chrisvirksomhed', 'bordingafdelingen');

    $response = $this->get("https://{$tenant->slug}.platebook.dk/{$location->slug}");

    $response->assertOk();
    $response->assertSee('Book din tid');
});

test('login domain root still renders when tenant subdomain routes are enabled', function () {
    $response = $this->get('https://login.platebook.dk/');

    $response->assertOk();
    $response->assertSee('Log ind');
});

test('tenant login path redirects directly to the dedicated login domain', function () {
    $response = $this->get('https://chrisvirksomhed.platebook.dk/login');

    $response->assertRedirect('https://login.platebook.dk');
});

test('legacy public booking url redirects to canonical tenant subdomain url', function () {
    [$tenant, $location] = createPublicTenantWithLocation('chrisvirksomhed', 'bordingafdelingen');

    $response = $this->get("https://platebook.dk/book-tid?tenant={$tenant->slug}&location_id={$location->id}");

    $response->assertRedirect("https://{$tenant->slug}.platebook.dk/{$location->slug}");
});

test('tenant root redirects to the first active location', function () {
    [$tenant] = createPublicTenantWithLocation('chrisvirksomhed', 'bordingafdelingen', 'Bordingafdelingen');

    Location::query()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Aalborg Afdeling',
        'slug' => 'aalborg-afdeling',
        'timezone' => 'Europe/Copenhagen',
        'is_active' => true,
    ]);

    $response = $this->get("https://{$tenant->slug}.platebook.dk/");

    $response->assertRedirect("https://{$tenant->slug}.platebook.dk/aalborg-afdeling");
});

test('non public app routes on tenant subdomain redirect back to the main app host', function () {
    [$tenant] = createPublicTenantWithLocation('chrisvirksomhed', 'bordingafdelingen');

    $response = $this->get("https://{$tenant->slug}.platebook.dk/platform/login");

    $response->assertRedirect('https://platebook.dk/platform/login');
});
