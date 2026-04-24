<?php

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Location;
use App\Models\LocationOpeningHour;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserWorkShift;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Hash;

it('authenticates native app users and returns booking data', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 4, 24, 9, 0, 0, 'Europe/Copenhagen'));

    $tenant = Tenant::query()->create([
        'name' => 'Test tenant',
        'slug' => 'test-tenant',
        'timezone' => 'Europe/Copenhagen',
        'is_active' => true,
        'work_shifts_enabled' => false,
    ]);

    $location = Location::query()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Hovedafdeling',
        'slug' => 'hovedafdeling',
        'timezone' => 'Europe/Copenhagen',
        'is_active' => true,
    ]);

    $otherLocation = Location::query()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Filial',
        'slug' => 'filial',
        'timezone' => 'Europe/Copenhagen',
        'is_active' => true,
    ]);

    LocationOpeningHour::query()->create([
        'location_id' => $location->id,
        'weekday' => 5,
        'opens_at' => '07:00:00',
        'closes_at' => '22:00:00',
    ]);

    LocationOpeningHour::query()->create([
        'location_id' => $otherLocation->id,
        'weekday' => 6,
        'opens_at' => '08:00:00',
        'closes_at' => '16:00:00',
    ]);

    $user = User::query()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Native User',
        'initials' => 'NU',
        'email' => 'native@example.test',
        'email_verified_at' => now(),
        'role' => User::ROLE_OWNER,
        'is_bookable' => true,
        'is_active' => true,
        'password' => Hash::make('secret-password'),
    ]);

    $location->users()->attach($user->id, ['is_active' => true]);
    $otherLocation->users()->attach($user->id, ['is_active' => true]);

    $otherUser = User::query()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Other User',
        'initials' => 'OU',
        'email' => 'other@example.test',
        'email_verified_at' => now(),
        'role' => User::ROLE_STAFF,
        'is_bookable' => true,
        'is_active' => true,
        'password' => Hash::make('secret-password'),
    ]);

    $location->users()->attach($otherUser->id, ['is_active' => true]);

    $category = ServiceCategory::query()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Behandling',
        'is_active' => true,
    ]);

    $service = Service::query()->create([
        'tenant_id' => $tenant->id,
        'service_category_id' => $category->id,
        'name' => 'Konsultation',
        'duration_minutes' => 45,
        'color' => '#5E7097',
        'is_online_bookable' => true,
    ]);

    $location->services()->attach($service->id, ['is_active' => true]);
    $otherLocation->services()->attach($service->id, ['is_active' => true]);
    $user->services()->attach($service->id);

    $customer = Customer::query()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Test Kunde',
    ]);

    Booking::query()->create([
        'tenant_id' => $tenant->id,
        'location_id' => $location->id,
        'customer_id' => $customer->id,
        'service_id' => $service->id,
        'staff_user_id' => $user->id,
        'starts_at' => '2026-04-24 10:00:00',
        'ends_at' => '2026-04-24 10:45:00',
        'status' => Booking::STATUS_CONFIRMED,
    ]);

    Booking::query()->create([
        'tenant_id' => $tenant->id,
        'location_id' => $location->id,
        'customer_id' => $customer->id,
        'service_id' => $service->id,
        'staff_user_id' => $otherUser->id,
        'starts_at' => '2026-04-24 11:00:00',
        'ends_at' => '2026-04-24 11:45:00',
        'status' => Booking::STATUS_CONFIRMED,
    ]);

    $loginResponse = $this->postJson('/api/native/login', [
        'email' => 'native@example.test',
        'password' => 'secret-password',
        'device_name' => 'Test iPhone',
    ]);

    $loginResponse
        ->assertOk()
        ->assertJsonPath('user.email', 'native@example.test')
        ->assertJsonStructure(['token']);

    $token = (string) $loginResponse->json('token');

    $this->withToken($token)
        ->getJson('/api/native/bootstrap')
        ->assertOk()
        ->assertJsonFragment(['name' => 'Hovedafdeling']);

    $this->withToken($token)
        ->getJson('/api/native/bookings?date=2026-04-24&location_id='.$location->id)
        ->assertOk()
        ->assertJsonCount(1, 'bookings')
        ->assertJsonPath('has_work_shift_for_date', true)
        ->assertJsonPath('calendar_grid.start_minutes', 420)
        ->assertJsonPath('calendar_grid.end_minutes', 1320)
        ->assertJsonPath('bookings.0.customer', 'Test Kunde')
        ->assertJsonPath('next_booking.customer', 'Test Kunde')
        ->assertJsonPath('services.0.name', 'Konsultation');

    UserWorkShift::query()->create([
        'tenant_id' => $tenant->id,
        'location_id' => $location->id,
        'user_id' => $user->id,
        'shift_date' => '2026-04-24',
        'starts_at' => '09:00:00',
        'ends_at' => '17:00:00',
        'work_role' => UserWorkShift::ROLE_SERVICE,
    ]);

    $this->withToken($token)
        ->getJson('/api/native/booking-options?booking_date=2026-04-24&location_id='.$location->id)
        ->assertOk()
        ->assertJsonCount(1, 'staff')
        ->assertJsonPath('staff.0.name', 'Native User')
        ->assertJsonPath('staff.0.service_ids.0', $service->id);

    $this->withToken($token)
        ->postJson('/api/native/bookings', [
            'location_id' => $location->id,
            'staff_user_id' => $user->id,
            'service_id' => $service->id,
            'booking_date' => '2026-04-24',
            'booking_time' => '12:00',
            'customer_name' => 'Ny Kunde',
            'customer_email' => 'ny@example.test',
        ])
        ->assertCreated()
        ->assertJsonPath('booking.customer', 'Ny Kunde')
        ->assertJsonPath('booking.time_range', '12:00 - 12:45');

    $this->assertDatabaseHas('bookings', [
        'tenant_id' => $tenant->id,
        'location_id' => $location->id,
        'service_id' => $service->id,
        'staff_user_id' => $user->id,
        'status' => Booking::STATUS_CONFIRMED,
    ]);

    $this->withToken($token)
        ->getJson('/api/native/bookings?date=2026-04-24&location_id='.$location->id)
        ->assertOk()
        ->assertJsonCount(2, 'bookings')
        ->assertJsonPath('next_booking.customer', 'Test Kunde');

    $tenant->forceFill(['work_shifts_enabled' => true])->save();

    UserWorkShift::query()->create([
        'tenant_id' => $tenant->id,
        'location_id' => $otherLocation->id,
        'user_id' => $user->id,
        'shift_date' => '2026-04-25',
        'starts_at' => '10:00:00',
        'ends_at' => '15:00:00',
        'work_role' => UserWorkShift::ROLE_SERVICE,
    ]);

    $this->withToken($token)
        ->getJson('/api/native/bookings?date=2026-04-25&location_id='.$location->id)
        ->assertOk()
        ->assertJsonPath('has_work_shift_for_date', false)
        ->assertJsonPath('work_shift_location.id', $otherLocation->id)
        ->assertJsonPath('work_shift_location.name', 'Filial')
        ->assertJsonPath('next_work_shift.location.name', 'Filial')
        ->assertJsonPath('next_work_shift.countdown_label', 'Næste vagt om')
        ->assertJsonPath('next_work_shift.time_range', '10:00 - 15:00');

    $this->withToken($token)
        ->getJson('/api/native/bookings?date=2026-04-25&location_id='.$otherLocation->id)
        ->assertOk()
        ->assertJsonPath('has_work_shift_for_date', true)
        ->assertJsonPath('work_shift_location', null)
        ->assertJsonPath('calendar_grid.work_shift_intervals.0.start_minutes', 600)
        ->assertJsonPath('calendar_grid.work_shift_intervals.0.end_minutes', 900);

    $this->withToken($token)
        ->getJson('/api/native/bookings?date=2026-04-24&location_id='.$location->id)
        ->assertOk()
        ->assertJsonPath('has_work_shift_for_date', true)
        ->assertJsonPath('calendar_grid.start_minutes', 420)
        ->assertJsonPath('calendar_grid.end_minutes', 1320)
        ->assertJsonPath('calendar_grid.work_shift_intervals.0.start_minutes', 540)
        ->assertJsonPath('calendar_grid.work_shift_intervals.0.end_minutes', 1020);

    CarbonImmutable::setTestNow();
});
