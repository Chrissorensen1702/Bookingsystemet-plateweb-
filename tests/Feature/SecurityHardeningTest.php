<?php

use App\Models\Location;
use App\Models\Tenant;
use App\Models\User;
use App\Support\RouteUrls;
use Illuminate\Http\UploadedFile;

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

function createSecurityTenantContext(): array
{
    $tenant = Tenant::query()->create([
        'name' => 'Chris Virksomhed',
        'slug' => 'chris-virksomhed',
        'timezone' => 'Europe/Copenhagen',
        'is_active' => true,
    ]);

    $location = Location::query()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Hovedafdeling',
        'slug' => 'hovedafdeling',
        'timezone' => 'Europe/Copenhagen',
        'is_active' => true,
    ]);

    $owner = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => User::ROLE_OWNER,
        'is_bookable' => false,
        'is_active' => true,
    ]);

    return [$tenant, $location, $owner];
}

test('trusted host patterns cover app login and tenant subdomains', function (): void {
    expect(RouteUrls::trustedHostPatterns())->toBe([
        '^platebook\\.dk$',
        '^login\\.platebook\\.dk$',
        '^(.+\\.)?platebook\\.dk$',
    ]);
});

test('authenticated internal pages disable caching and vary by cookie', function (): void {
    [, , $owner] = createSecurityTenantContext();

    $response = $this
        ->actingAs($owner)
        ->get('https://platebook.dk/profil');

    $response->assertOk();
    expect((string) $response->headers->get('Cache-Control'))->toContain('no-store');
    expect((string) $response->headers->get('Cache-Control'))->toContain('private');
    expect((string) $response->headers->get('Vary'))->toContain('Cookie');
});

test('preview booking pages disable caching', function (): void {
    [$tenant, $location] = createSecurityTenantContext();

    $response = $this->get(
        "https://platebook.dk/book-tid/preview?tenant={$tenant->slug}&location_id={$location->id}&preview=1"
    );

    $response->assertOk();
    expect((string) $response->headers->get('Cache-Control'))->toContain('no-store');
    expect((string) $response->headers->get('Vary'))->toContain('Cookie');
});

test('branding rejects svg logo uploads', function (): void {
    [$tenant, $location, $owner] = createSecurityTenantContext();

    $response = $this
        ->actingAs($owner)
        ->from('https://platebook.dk/indstillinger?location_id='.$location->id.'&settings_view=branding')
        ->patch('https://platebook.dk/indstillinger', [
            'location_id' => $location->id,
            'settings_view' => 'branding',
            'slug' => $tenant->slug,
            'public_logo_file' => UploadedFile::fake()->create('logo.svg', 8, 'image/svg+xml'),
        ]);

    $response->assertRedirect();
    $response->assertSessionHasErrors('public_logo_file');
});
