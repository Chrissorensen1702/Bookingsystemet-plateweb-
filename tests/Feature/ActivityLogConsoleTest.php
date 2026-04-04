<?php

use App\Models\ActivityEvent;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\Tenant;
use App\Models\User;
use App\Support\ActivityLogger;
use Carbon\CarbonImmutable;

test('activity logger stores readable booking creation events', function () {
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
        'name' => 'Chris Soerensen',
        'role' => User::ROLE_OWNER,
        'is_bookable' => false,
        'is_active' => true,
    ]);

    $staff = User::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Mathilde Jensen',
        'role' => User::ROLE_STAFF,
        'is_bookable' => true,
        'is_active' => true,
    ]);
    $staff->locations()->attach($location->id, ['is_active' => true]);

    $category = ServiceCategory::query()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Klip',
        'description' => null,
        'sort_order' => 1,
        'is_active' => true,
    ]);

    $service = Service::query()->create([
        'tenant_id' => $tenant->id,
        'service_category_id' => $category->id,
        'name' => 'Dameklip',
        'duration_minutes' => 45,
        'price_minor' => 49900,
        'color' => '#9BD4FF',
        'description' => 'Test ydelse',
        'is_online_bookable' => true,
        'requires_staff_selection' => true,
        'sort_order' => 1,
        'buffer_before_minutes' => 0,
        'buffer_after_minutes' => 0,
        'min_notice_minutes' => 0,
        'max_advance_days' => 30,
        'cancellation_notice_hours' => 24,
    ]);
    $service->locations()->attach($location->id, [
        'duration_minutes' => 45,
        'price_minor' => 49900,
        'is_active' => true,
    ]);

    $customer = Customer::query()->create([
        'tenant_id' => $tenant->id,
        'name' => 'Emma Hansen',
        'email' => 'emma@example.test',
        'phone' => '12345678',
    ]);

    $booking = Booking::query()->create([
        'tenant_id' => $tenant->id,
        'location_id' => $location->id,
        'customer_id' => $customer->id,
        'service_id' => $service->id,
        'staff_user_id' => $staff->id,
        'starts_at' => CarbonImmutable::parse('2026-04-04 09:00:00', 'Europe/Copenhagen'),
        'ends_at' => CarbonImmutable::parse('2026-04-04 09:45:00', 'Europe/Copenhagen'),
        'buffer_before_minutes' => 0,
        'buffer_after_minutes' => 0,
        'status' => Booking::STATUS_CONFIRMED,
        'notes' => null,
    ]);

    app(ActivityLogger::class)->logBookingCreated($owner, $booking);

    $event = ActivityEvent::query()->latest('id')->first();

    expect($event)->not->toBeNull();
    expect($event->category)->toBe('bookings');
    expect($event->event_key)->toBe('booking.created');
    expect($event->message)->toContain('Chris Soerensen oprettede booking for Emma Hansen.');
    expect(collect($event->metadata['context'] ?? [])->pluck('value')->all())->toContain('Dameklip');
    expect(collect($event->metadata['context'] ?? [])->pluck('value')->all())->toContain('Hovedafdeling');
});
