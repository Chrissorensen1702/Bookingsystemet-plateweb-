<?php

use Database\Seeders\BookingDemoSeeder;

test('the booking calendar renders seeded bookings', function () {
    $this->seed(BookingDemoSeeder::class);

    $response = $this->get(route('booking-calender'));

    $response->assertOk();
    $response->assertSee('Maria Jensen');
});
