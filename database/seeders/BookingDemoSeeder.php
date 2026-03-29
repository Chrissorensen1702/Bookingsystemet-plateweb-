<?php

namespace Database\Seeders;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class BookingDemoSeeder extends Seeder
{
    public function run(): void
    {
        Booking::query()->delete();
        Customer::query()->delete();
        Service::query()->delete();
        ServiceCategory::query()->delete();

        $tenant = Tenant::query()->firstOrCreate(
            ['slug' => 'default'],
            [
                'name' => 'Standard virksomhed',
                'timezone' => (string) config('app.timezone', 'UTC'),
                'is_active' => true,
            ],
        );
        $tenantId = (int) $tenant->id;
        $location = Location::query()->firstOrCreate(
            [
                'tenant_id' => $tenantId,
                'slug' => 'hovedafdeling',
            ],
            [
                'name' => 'Hovedafdeling',
                'timezone' => (string) ($tenant->timezone ?: config('app.timezone', 'UTC')),
                'is_active' => true,
            ],
        );
        $locationId = (int) $location->id;

        $staff = [
            'Emma' => $this->upsertStaffUser($tenantId, 'Emma', 'emma@example.test', 'EM'),
            'Laerke' => $this->upsertStaffUser($tenantId, 'Laerke', 'laerke@example.test', 'LA'),
            'Mie' => $this->upsertStaffUser($tenantId, 'Mie', 'mie@example.test', 'MI'),
            'Jonas' => $this->upsertStaffUser($tenantId, 'Jonas', 'jonas@example.test', 'JO'),
        ];
        $location->users()->syncWithPivotValues(
            collect($staff)->map(fn (User $user): int => $user->id)->values()->all(),
            ['is_active' => true],
            false
        );

        $categories = [
            'Rådgivning' => ServiceCategory::query()->create([
                'tenant_id' => $tenantId,
                'name' => 'Rådgivning',
                'description' => 'Indledende dialog og opfølgning.',
            ]),
            'Premium' => ServiceCategory::query()->create([
                'tenant_id' => $tenantId,
                'name' => 'Premium',
                'description' => 'Udvidede behandlinger med ekstra tid.',
            ]),
            'Behandling' => ServiceCategory::query()->create([
                'tenant_id' => $tenantId,
                'name' => 'Behandling',
                'description' => 'Standardydelser til daglig drift.',
            ]),
            'Internt' => ServiceCategory::query()->create([
                'tenant_id' => $tenantId,
                'name' => 'Internt',
                'description' => 'Interne blokeringer og opgaver.',
            ]),
        ];

        $services = [
            'Konsultation' => Service::query()->create([
                'tenant_id' => $tenantId,
                'service_category_id' => $categories['Rådgivning']->id,
                'name' => 'Konsultation',
                'duration_minutes' => 60,
                'color' => '#5C80BC',
            ]),
            'Opfoelgning' => Service::query()->create([
                'tenant_id' => $tenantId,
                'service_category_id' => $categories['Rådgivning']->id,
                'name' => 'Opfoelgning',
                'duration_minutes' => 45,
                'color' => '#E8C547',
            ]),
            'VIP booking' => Service::query()->create([
                'tenant_id' => $tenantId,
                'service_category_id' => $categories['Premium']->id,
                'name' => 'VIP booking',
                'duration_minutes' => 90,
                'color' => '#30323D',
            ]),
            'Standard' => Service::query()->create([
                'tenant_id' => $tenantId,
                'service_category_id' => $categories['Behandling']->id,
                'name' => 'Standard',
                'duration_minutes' => 60,
                'color' => '#5C80BC',
            ]),
            'Ekspres' => Service::query()->create([
                'tenant_id' => $tenantId,
                'service_category_id' => $categories['Behandling']->id,
                'name' => 'Ekspres',
                'duration_minutes' => 30,
                'color' => '#A66A4D',
            ]),
            'Internt' => Service::query()->create([
                'tenant_id' => $tenantId,
                'service_category_id' => $categories['Internt']->id,
                'name' => 'Internt',
                'duration_minutes' => 30,
                'color' => '#CDD1C4',
            ]),
        ];
        $location->services()->syncWithPivotValues(
            collect($services)->map(fn (Service $service): int => $service->id)->values()->all(),
            [
                'duration_minutes' => null,
                'price_minor' => null,
                'is_active' => true,
            ],
            false
        );

        $customers = collect([
            'Morgenhold', 'Maria Jensen', 'Anders Holm', 'Sofie Lund', 'Aftenhold',
            'Camilla Bruun', 'Thomas Nissen', 'Pia Koch', 'Mikkel Bech', 'Line Ravn',
            'Julie Madsen', 'Nicolai Berg', 'Helle Moesgaard', 'Clara Birk',
            'Sara Olesen', 'Frederik Valeur', 'Mona Winther', 'Lukkerunde',
            'Lena Rask', 'Kasper Birk', 'Anne Dam', 'Jon Madsen',
            'Weekendkunde', 'Nora Holm', 'Akut tid', 'Soendagshold', 'Eva Bilde', 'Ugeplan',
        ])->mapWithKeys(fn (string $name) => [
            $name => Customer::query()->create([
                'tenant_id' => $tenantId,
                'name' => $name,
            ]),
        ]);

        $weekStart = now()->startOfWeek(Carbon::MONDAY)->startOfDay();

        $rows = [
            ['day' => 0, 'start' => '07:15', 'duration' => 30, 'customer' => 'Morgenhold', 'service' => 'Internt', 'staff' => 'Emma', 'status' => Booking::STATUS_COMPLETED],
            ['day' => 0, 'start' => '09:00', 'duration' => 60, 'customer' => 'Maria Jensen', 'service' => 'Konsultation', 'staff' => 'Emma', 'status' => 'confirmed'],
            ['day' => 0, 'start' => '12:15', 'duration' => 45, 'customer' => 'Anders Holm', 'service' => 'Opfoelgning', 'staff' => 'Laerke', 'status' => Booking::STATUS_CONFIRMED],
            ['day' => 0, 'start' => '15:00', 'duration' => 90, 'customer' => 'Sofie Lund', 'service' => 'VIP booking', 'staff' => 'Mie', 'status' => Booking::STATUS_CONFIRMED],
            ['day' => 0, 'start' => '19:30', 'duration' => 45, 'customer' => 'Aftenhold', 'service' => 'Standard', 'staff' => 'Jonas', 'status' => 'confirmed'],
            ['day' => 1, 'start' => '08:00', 'duration' => 30, 'customer' => 'Camilla Bruun', 'service' => 'Standard', 'staff' => 'Emma', 'status' => 'confirmed'],
            ['day' => 1, 'start' => '10:15', 'duration' => 60, 'customer' => 'Thomas Nissen', 'service' => 'Standard', 'staff' => 'Jonas', 'status' => 'confirmed'],
            ['day' => 1, 'start' => '13:00', 'duration' => 45, 'customer' => 'Pia Koch', 'service' => 'Opfoelgning', 'staff' => 'Laerke', 'status' => Booking::STATUS_CONFIRMED],
            ['day' => 1, 'start' => '16:30', 'duration' => 30, 'customer' => 'Mikkel Bech', 'service' => 'Ekspres', 'staff' => 'Mie', 'status' => Booking::STATUS_CONFIRMED],
            ['day' => 1, 'start' => '20:00', 'duration' => 60, 'customer' => 'Line Ravn', 'service' => 'Konsultation', 'staff' => 'Emma', 'status' => 'confirmed'],
            ['day' => 2, 'start' => '09:00', 'duration' => 90, 'customer' => 'Julie Madsen', 'service' => 'VIP booking', 'staff' => 'Emma', 'status' => Booking::STATUS_CONFIRMED],
            ['day' => 2, 'start' => '11:30', 'duration' => 30, 'customer' => 'Nicolai Berg', 'service' => 'Ekspres', 'staff' => 'Jonas', 'status' => 'confirmed'],
            ['day' => 2, 'start' => '14:15', 'duration' => 60, 'customer' => 'Helle Moesgaard', 'service' => 'Opfoelgning', 'staff' => 'Mie', 'status' => 'confirmed'],
            ['day' => 2, 'start' => '18:00', 'duration' => 45, 'customer' => 'Clara Birk', 'service' => 'Opfoelgning', 'staff' => 'Laerke', 'status' => Booking::STATUS_CONFIRMED],
            ['day' => 3, 'start' => '08:00', 'duration' => 30, 'customer' => 'Sara Olesen', 'service' => 'Standard', 'staff' => 'Laerke', 'status' => 'confirmed'],
            ['day' => 3, 'start' => '12:00', 'duration' => 60, 'customer' => 'Frederik Valeur', 'service' => 'Konsultation', 'staff' => 'Emma', 'status' => Booking::STATUS_CONFIRMED],
            ['day' => 3, 'start' => '16:15', 'duration' => 45, 'customer' => 'Mona Winther', 'service' => 'Standard', 'staff' => 'Jonas', 'status' => 'confirmed'],
            ['day' => 3, 'start' => '21:00', 'duration' => 30, 'customer' => 'Lukkerunde', 'service' => 'Internt', 'staff' => 'Jonas', 'status' => Booking::STATUS_COMPLETED],
            ['day' => 4, 'start' => '10:00', 'duration' => 30, 'customer' => 'Lena Rask', 'service' => 'Ekspres', 'staff' => 'Mie', 'status' => 'confirmed'],
            ['day' => 4, 'start' => '13:00', 'duration' => 60, 'customer' => 'Kasper Birk', 'service' => 'VIP booking', 'staff' => 'Emma', 'status' => Booking::STATUS_CONFIRMED],
            ['day' => 4, 'start' => '15:15', 'duration' => 45, 'customer' => 'Anne Dam', 'service' => 'Ekspres', 'staff' => 'Laerke', 'status' => Booking::STATUS_CONFIRMED],
            ['day' => 4, 'start' => '17:30', 'duration' => 30, 'customer' => 'Jon Madsen', 'service' => 'Ekspres', 'staff' => 'Jonas', 'status' => 'confirmed'],
            ['day' => 5, 'start' => '09:15', 'duration' => 45, 'customer' => 'Weekendkunde', 'service' => 'Standard', 'staff' => 'Emma', 'status' => 'confirmed'],
            ['day' => 5, 'start' => '11:00', 'duration' => 60, 'customer' => 'Nora Holm', 'service' => 'VIP booking', 'staff' => 'Mie', 'status' => Booking::STATUS_CONFIRMED],
            ['day' => 5, 'start' => '14:30', 'duration' => 30, 'customer' => 'Akut tid', 'service' => 'Ekspres', 'staff' => 'Jonas', 'status' => Booking::STATUS_CONFIRMED],
            ['day' => 6, 'start' => '10:00', 'duration' => 45, 'customer' => 'Soendagshold', 'service' => 'Standard', 'staff' => 'Laerke', 'status' => 'confirmed'],
            ['day' => 6, 'start' => '13:15', 'duration' => 45, 'customer' => 'Eva Bilde', 'service' => 'Opfoelgning', 'staff' => 'Emma', 'status' => Booking::STATUS_CANCELED],
            ['day' => 6, 'start' => '16:00', 'duration' => 60, 'customer' => 'Ugeplan', 'service' => 'Internt', 'staff' => 'Jonas', 'status' => Booking::STATUS_COMPLETED],
        ];

        foreach ($rows as $row) {
            $startsAt = $weekStart->copy()->addDays($row['day'])->setTimeFromTimeString($row['start']);
            $endsAt = $startsAt->copy()->addMinutes($row['duration']);
            $payload = [
                'tenant_id' => $tenantId,
                'location_id' => $locationId,
                'customer_id' => $customers[$row['customer']]->id,
                'service_id' => $services[$row['service']]->id,
                'staff_user_id' => $staff[$row['staff']]->id,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'status' => $row['status'],
            ];

            if ($row['status'] === Booking::STATUS_COMPLETED) {
                $payload['completed_at'] = $endsAt;
            }

            Booking::query()->create($payload);
        }
    }

    private function upsertStaffUser(int $tenantId, string $name, string $email, string $initials): User
    {
        /** @var User $user */
        $user = User::query()->firstOrCreate(
            ['email' => mb_strtolower(trim($email))],
            [
                'tenant_id' => $tenantId,
                'name' => $name,
                'role' => User::ROLE_STAFF,
                'initials' => strtoupper(trim($initials)),
                'is_bookable' => true,
                'password' => Hash::make(Str::random(40)),
            ],
        );

        $user->forceFill([
            'tenant_id' => $tenantId,
            'name' => $name,
            'role' => User::ROLE_STAFF,
            'initials' => strtoupper(trim($initials)),
            'is_bookable' => true,
        ])->save();

        return $user;
    }
}
