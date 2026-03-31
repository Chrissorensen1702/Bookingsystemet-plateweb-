<?php

use App\Http\Controllers\BrandingSettingsController;
use App\Models\Location;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ViewErrorBag;

beforeEach(function (): void {
    putenv('APP_URL=https://platebook.dk');
    putenv('PUBLIC_ROOT_DOMAIN=platebook.dk');

    $_ENV['APP_URL'] = 'https://platebook.dk';
    $_SERVER['APP_URL'] = 'https://platebook.dk';
    $_ENV['PUBLIC_ROOT_DOMAIN'] = 'platebook.dk';
    $_SERVER['PUBLIC_ROOT_DOMAIN'] = 'platebook.dk';

    $this->refreshApplication();
    config([
        'app.url' => 'https://platebook.dk',
        'security.domains.public_root' => 'platebook.dk',
    ]);
    $this->artisan('migrate:fresh', ['--force' => true]);
});

afterEach(function (): void {
    putenv('APP_URL');
    putenv('PUBLIC_ROOT_DOMAIN');

    unset(
        $_ENV['APP_URL'],
        $_SERVER['APP_URL'],
        $_ENV['PUBLIC_ROOT_DOMAIN'],
        $_SERVER['PUBLIC_ROOT_DOMAIN'],
    );

    $this->refreshApplication();
});

test('owner can update the tenant subdomain slug from global settings', function () {
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

    $request = Request::create('/indstillinger', 'PATCH', [
        'settings_view' => 'branding',
        'location_id' => $location->id,
        'slug' => 'ny-chris-virksomhed',
        'public_brand_name' => 'Chris Booking',
    ]);
    $request->setUserResolver(static fn ($guard = null): User => $owner);

    app()->instance('request', $request);
    Auth::setUser($owner);

    $response = app(BrandingSettingsController::class)->update($request);

    expect($response->getStatusCode())->toBe(302);
    expect($tenant->fresh()->slug)->toBe('ny-chris-virksomhed');
});

test('branding settings view renders the global settings form for owners', function () {
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

    $request = Request::create('/indstillinger', 'GET', [
        'settings_view' => 'branding',
        'location_id' => $location->id,
    ]);
    $request->setUserResolver(static fn ($guard = null): User => $owner);

    app()->instance('request', $request);
    Auth::setUser($owner);
    view()->share('errors', new ViewErrorBag());

    $view = app(BrandingSettingsController::class)->index($request);
    $html = $view->render();

    expect($html)->toContain('Virksomheds-slug');
    expect($html)->toContain('Global branding');
    expect($html)->not->toContain('Gem lokationsindstillinger');
});

test('permissions settings view renders the role permissions matrix', function () {
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

    $request = Request::create('/indstillinger', 'GET', [
        'settings_view' => 'permissions',
        'location_id' => $location->id,
    ]);
    $request->setUserResolver(static fn ($guard = null): User => $owner);

    app()->instance('request', $request);
    Auth::setUser($owner);
    view()->share('errors', new ViewErrorBag());

    $view = app(BrandingSettingsController::class)->index($request);
    $html = $view->render();

    expect($html)->toContain('Adgangsrettigheder pr. rolle');
    expect($html)->toContain('Gem rettigheder');
});

test('activity settings view renders the employee status overview', function () {
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
        'name' => 'Chris Sørensen',
        'role' => User::ROLE_OWNER,
        'is_bookable' => false,
        'is_active' => true,
    ]);

    User::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Andreas Blæsbjerg',
        'role' => User::ROLE_STAFF,
        'is_bookable' => true,
        'is_active' => true,
    ]);

    $request = Request::create('/indstillinger', 'GET', [
        'settings_view' => 'activity',
        'location_id' => $location->id,
    ]);
    $request->setUserResolver(static fn ($guard = null): User => $owner);

    app()->instance('request', $request);
    Auth::setUser($owner);
    view()->share('errors', new ViewErrorBag());

    $view = app(BrandingSettingsController::class)->index($request);
    $html = $view->render();

    expect($html)->toContain('Status og historik');
    expect($html)->toContain('Andreas Blæsbjerg');
    expect($html)->toContain('Bookbar: Ja');
});
