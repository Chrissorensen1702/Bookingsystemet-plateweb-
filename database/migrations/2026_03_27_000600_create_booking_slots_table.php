<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_slots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')
                ->constrained('tenants')
                ->cascadeOnDelete();
            $table->foreignId('location_id')
                ->constrained('locations')
                ->cascadeOnDelete();
            $table->foreignId('booking_id')
                ->constrained('bookings')
                ->cascadeOnDelete();
            $table->foreignId('staff_user_id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->dateTime('slot_start');
            $table->timestamps();

            $table->unique(['tenant_id', 'staff_user_id', 'slot_start'], 'booking_slots_staff_slot_unique');
            $table->unique(['booking_id', 'slot_start'], 'booking_slots_booking_slot_unique');
            $table->index(['tenant_id', 'location_id', 'slot_start'], 'booking_slots_tenant_location_slot_index');
        });

        $now = now();

        DB::table('bookings')
            ->select([
                'id',
                'tenant_id',
                'location_id',
                'staff_user_id',
                'starts_at',
                'ends_at',
                'status',
            ])
            ->whereNotNull('staff_user_id')
            ->where('status', '!=', 'canceled')
            ->orderBy('id')
            ->chunkById(200, function ($bookings) use ($now): void {
                $rows = [];

                foreach ($bookings as $booking) {
                    $bookingId = (int) $booking->id;
                    $tenantId = (int) $booking->tenant_id;
                    $locationId = (int) $booking->location_id;
                    $staffUserId = (int) $booking->staff_user_id;

                    if ($bookingId <= 0 || $tenantId <= 0 || $locationId <= 0 || $staffUserId <= 0) {
                        continue;
                    }

                    $startsAt = CarbonImmutable::parse((string) $booking->starts_at, (string) config('app.timezone', 'UTC'));
                    $endsAt = CarbonImmutable::parse((string) $booking->ends_at, (string) config('app.timezone', 'UTC'));

                    if (! $startsAt->lt($endsAt)) {
                        continue;
                    }

                    $minutes = (int) $startsAt->format('i');
                    $cursor = $startsAt
                        ->setMinute(intdiv($minutes, 15) * 15)
                        ->setSecond(0)
                        ->setMicrosecond(0);

                    while ($cursor->lt($endsAt)) {
                        $rows[] = [
                            'tenant_id' => $tenantId,
                            'location_id' => $locationId,
                            'booking_id' => $bookingId,
                            'staff_user_id' => $staffUserId,
                            'slot_start' => $cursor->format('Y-m-d H:i:s'),
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];

                        $cursor = $cursor->addMinutes(15);
                    }
                }

                if ($rows !== []) {
                    DB::table('booking_slots')->insertOrIgnore($rows);
                }
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_slots');
    }
};

