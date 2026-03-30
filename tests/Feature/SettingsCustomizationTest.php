<?php

use App\Http\Controllers\BrandingSettingsController;
use App\Models\Location;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

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

function createSettingsTenantContext(): array
{
    $tenant = Tenant::query()->create([
        'name' => 'Chris Virksomhed',
        'slug' => 'chris-virksomhed',
        'timezone' => 'Europe/Copenhagen',
        'require_service_categories' => false,
        'work_shifts_enabled' => false,
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

test('owner can update local settings with location name and a custom confirmation message', function () {
    [$tenant, $location, $owner] = createSettingsTenantContext();

    $request = Request::create('/indstillinger', 'PATCH', [
        'update_booking_intro' => 1,
        'settings_view' => 'location',
        'location_id' => $location->id,
        'location_name' => 'Bording Centrum',
        'location_public_booking_intro_text' => 'Velkommen til Bording Centrum.',
        'location_public_booking_confirmation_text' => 'Tak for din booking hos Bording Centrum.',
        'location_address_line_1' => 'Storegade 12',
        'location_address_line_2' => '1. sal',
        'location_postal_code' => '7441',
        'location_city' => 'Bording',
        'location_public_contact_phone' => '+45 12 34 56 78',
        'location_public_contact_email' => 'booking@bording.test',
    ]);
    $request->setUserResolver(static fn ($guard = null): User => $owner);

    app()->instance('request', $request);
    Auth::setUser($owner);

    $response = app(BrandingSettingsController::class)->update($request);

    expect($response->getStatusCode())->toBe(302);

    $location->refresh();

    expect($location->name)->toBe('Bording Centrum');
    expect($location->public_booking_confirmation_text)->toBe('Tak for din booking hos Bording Centrum.');
});

test('public booking stores a custom confirmation message in session after booking', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Chris Virksomhed',
        'slug' => 'chris-virksomhed',
        'timezone' => 'Europe/Copenhagen',
        'require_service_categories' => false,
        'work_shifts_enabled' => false,
        'is_active' => true,
    ]);

    $location = Location::query()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Bordingafdelingen',
        'slug' => 'bordingafdelingen',
        'timezone' => 'Europe/Copenhagen',
        'public_booking_confirmation_text' => 'Tak for din booking hos Bordingafdelingen.',
        'is_active' => true,
    ]);

    $category = ServiceCategory::query()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Standard',
        'is_active' => true,
    ]);

    $service = Service::query()->create([
        'tenant_id' => $tenant->id,
        'service_category_id' => $category->id,
        'name' => 'Klip',
        'duration_minutes' => 30,
        'is_online_bookable' => true,
        'requires_staff_selection' => false,
    ]);

    DB::table('location_service')->insert([
        'location_id' => $location->id,
        'service_id' => $service->id,
        'duration_minutes' => null,
        'price_minor' => null,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $staff = User::factory()->create([
        'tenant_id' => $tenant->id,
        'role' => User::ROLE_STAFF,
        'is_bookable' => true,
        'is_active' => true,
        'competency_scope' => User::COMPETENCY_SCOPE_GLOBAL,
    ]);

    DB::table('location_user')->insert([
        'location_id' => $location->id,
        'user_id' => $staff->id,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('service_user')->insert([
        'service_id' => $service->id,
        'user_id' => $staff->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $bookingDate = CarbonImmutable::now('Europe/Copenhagen')->addDay();

    DB::table('location_opening_hours')->insert([
        'location_id' => $location->id,
        'weekday' => $bookingDate->isoWeekday(),
        'opens_at' => '08:00:00',
        'closes_at' => '18:00:00',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $this->post("https://{$tenant->slug}.platebook.dk/{$location->slug}", [
        'service_id' => $service->id,
        'booking_date' => $bookingDate->format('Y-m-d'),
        'booking_time' => '10:00',
        'name' => 'Kunde Test',
        'email' => 'kunde@example.test',
        'phone' => '+45 11 22 33 44',
        'notes' => 'Ring gerne ved behov.',
    ]);

    $response->assertRedirect("https://{$tenant->slug}.platebook.dk/{$location->slug}");
    $response->assertSessionHas('status', 'Tak for din booking hos Bordingafdelingen.');
});
