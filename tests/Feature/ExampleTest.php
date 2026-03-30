<?php

use Database\Seeders\BookingDemoSeeder;
use App\Models\User;

test('the booking calendar renders seeded bookings', function () {
    $this->seed(BookingDemoSeeder::class);
    $user = User::query()->firstOrFail();
    $user->forceFill(['email_verified_at' => now()])->save();
    $this->actingAs($user);

    $response = $this->get(route('booking-calender'));

    $response->assertOk();
    $response->assertSee('Maria Jensen');
});
