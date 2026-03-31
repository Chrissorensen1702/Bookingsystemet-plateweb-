<?php

use App\Http\Controllers\UserManagementController;
use App\Models\Location;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

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

function createUsersSettingsContext(): array
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

test('users permissions view redirects to settings permissions', function () {
    [, $location, $owner] = createUsersSettingsContext();

    $request = Request::create('/brugere', 'GET', [
        'users_view' => 'permissions',
    ]);
    $request->setUserResolver(static fn ($guard = null): User => $owner);

    app()->instance('request', $request);
    Auth::setUser($owner);

    $response = app(UserManagementController::class)->index($request);

    expect($response->getStatusCode())->toBe(302);
    expect($response->headers->get('Location'))->toBe(route('settings.index', [
        'location_id' => $location->id,
        'settings_view' => 'permissions',
    ]));
});

test('users activity view redirects to settings activity', function () {
    [, $location, $owner] = createUsersSettingsContext();

    $request = Request::create('/brugere', 'GET', [
        'users_view' => 'activity',
    ]);
    $request->setUserResolver(static fn ($guard = null): User => $owner);

    app()->instance('request', $request);
    Auth::setUser($owner);

    $response = app(UserManagementController::class)->index($request);

    expect($response->getStatusCode())->toBe(302);
    expect($response->headers->get('Location'))->toBe(route('settings.index', [
        'location_id' => $location->id,
        'settings_view' => 'activity',
    ]));
});

test('updating role permissions redirects back to settings permissions', function () {
    [, $location, $owner] = createUsersSettingsContext();

    $request = Request::create('/brugere/rettigheder', 'PATCH', [
        'location_id' => $location->id,
        'settings_view' => 'permissions',
        'permissions' => [
            'manager' => [
                'bookings.manage' => '1',
            ],
        ],
    ]);
    $request->setUserResolver(static fn ($guard = null): User => $owner);

    app()->instance('request', $request);
    Auth::setUser($owner);

    $response = app(UserManagementController::class)->updatePermissions($request);

    expect($response->getStatusCode())->toBe(302);
    expect($response->headers->get('Location'))->toBe(route('settings.index', [
        'location_id' => $location->id,
        'settings_view' => 'permissions',
    ]));
});
