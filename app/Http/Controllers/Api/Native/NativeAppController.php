<?php

namespace App\Http\Controllers\Api\Native;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserWorkShift;
use App\Support\ActivityLogger;
use App\Support\BookingSlotManager;
use App\Support\BookingSmsNotifier;
use App\Support\LocationAvailability;
use App\Support\WorkShiftAvailability;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class NativeAppController extends Controller
{
    public function bootstrap(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $tenantId = $this->resolveTenantId($request);
        $locations = $this->locationsFor($request, $tenantId);
        $defaultLocation = $locations->first();

        return response()->json([
            'user' => $this->userPayload($user),
            'tenant' => [
                'id' => $tenantId,
                'name' => (string) ($user->tenant?->name ?? ''),
                'timezone' => (string) ($user->tenant?->timezone ?? config('app.timezone', 'UTC')),
            ],
            'locations' => $locations->values(),
            'default_location_id' => $defaultLocation['id'] ?? null,
        ]);
    }

    public function bookings(
        Request $request,
        LocationAvailability $availability,
        WorkShiftAvailability $shiftAvailability
    ): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);
        $locationId = $this->resolveLocationId($request, $tenantId);

        abort_if($tenantId <= 0 || $locationId <= 0, 404);

        $locationTimezone = (string) (Location::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($locationId)
            ->value('timezone') ?: config('app.timezone', 'UTC'));

        $selectedDate = $this->resolveDate((string) $request->query('date', ''), $locationTimezone);
        $status = $this->normalizeStatus((string) $request->query('status', 'active'));
        $userId = (int) ($request->user()?->id ?? 0);
        $hasWorkShiftForDate = $this->hasWorkShiftForDate(
            $tenantId,
            $locationId,
            $userId,
            $selectedDate
        );
        $workShiftLocation = $hasWorkShiftForDate
            ? null
            : $this->workShiftLocationForDate($request, $tenantId, $userId, $selectedDate);
        $nextWorkShift = $this->nextWorkShiftForUser($request, $tenantId, $userId, $locationTimezone);
        $calendarGrid = $this->calendarGridForDate(
            $availability,
            $shiftAvailability,
            $tenantId,
            $locationId,
            $userId,
            $selectedDate
        );

        $bookings = Booking::query()
            ->with($this->bookingRelations())
            ->where('tenant_id', $tenantId)
            ->where('location_id', $locationId)
            ->where('staff_user_id', $userId)
            ->whereBetween('starts_at', [$selectedDate->startOfDay(), $selectedDate->endOfDay()])
            ->when($status !== 'all', function (Builder $query) use ($status): void {
                if ($status === 'active') {
                    $query->where('status', Booking::STATUS_CONFIRMED);
                } else {
                    $query->where('status', $status);
                }
            })
            ->orderBy('starts_at')
            ->get()
            ->map(fn (Booking $booking): array => $this->bookingPayload($booking))
            ->values();

        $nextBooking = Booking::query()
            ->with($this->bookingRelations())
            ->where('tenant_id', $tenantId)
            ->where('location_id', $locationId)
            ->where('staff_user_id', $userId)
            ->where('status', Booking::STATUS_CONFIRMED)
            ->where('starts_at', '>=', CarbonImmutable::now($locationTimezone))
            ->orderBy('starts_at')
            ->first();

        return response()->json([
            'date' => $selectedDate->toDateString(),
            'location_id' => $locationId,
            'bookings' => $bookings,
            'next_booking' => $nextBooking ? $this->bookingPayload($nextBooking) : null,
            'has_work_shift_for_date' => $hasWorkShiftForDate,
            'work_shift_location' => $workShiftLocation,
            'next_work_shift' => $nextWorkShift,
            'calendar_grid' => $calendarGrid,
            'services' => $this->servicesFor($tenantId, $locationId),
            'staff' => $this->staffFor($tenantId, $locationId),
        ]);
    }

    public function services(Request $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);
        $locationId = $this->resolveLocationId($request, $tenantId);

        abort_if($tenantId <= 0 || $locationId <= 0, 404);

        return response()->json([
            'location_id' => $locationId,
            'services' => $this->servicesFor($tenantId, $locationId),
        ]);
    }

    public function bookingOptions(Request $request): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);
        $locationId = $this->resolveLocationId($request, $tenantId);

        abort_if($tenantId <= 0 || $locationId <= 0, 404);

        $locationTimezone = (string) (Location::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($locationId)
            ->value('timezone') ?: config('app.timezone', 'UTC'));

        $selectedDate = $this->resolveDate((string) $request->query('booking_date', ''), $locationTimezone);
        $services = $this->servicesFor($tenantId, $locationId);
        $activeServiceIds = $services
            ->pluck('id')
            ->map(static fn (int $id): int => (int) $id)
            ->all();

        return response()->json([
            'booking_date' => $selectedDate->toDateString(),
            'location_id' => $locationId,
            'staff' => $this->staffForBookingOptions($tenantId, $locationId, $selectedDate, $activeServiceIds),
            'services' => $services,
        ]);
    }

    public function storeBooking(
        Request $request,
        LocationAvailability $availability,
        BookingSlotManager $slotManager,
        WorkShiftAvailability $shiftAvailability,
        BookingSmsNotifier $smsNotifier,
        ?ActivityLogger $activityLogger = null
    ): JsonResponse
    {
        $activityLogger ??= app(ActivityLogger::class);
        $tenantId = $this->resolveTenantId($request);
        abort_if($tenantId <= 0, 500, 'Ingen aktiv tenant er konfigureret.');

        /** @var User $actor */
        $actor = $request->user();

        $validated = $request->validate([
            'location_id' => [
                'required',
                'integer',
                Rule::exists('locations', 'id')->where(
                    fn ($query) => $query
                        ->where('tenant_id', $tenantId)
                        ->where('is_active', true)
                ),
            ],
            'service_id' => [
                'required',
                'integer',
                Rule::exists('services', 'id')->where(
                    fn ($query) => $query->where('tenant_id', $tenantId)
                ),
            ],
            'staff_user_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where(
                    fn ($query) => $query
                        ->where('tenant_id', $tenantId)
                        ->where('is_bookable', true)
                        ->where('is_active', true)
                ),
            ],
            'booking_date' => ['required', 'date_format:Y-m-d'],
            'booking_time' => ['required', 'date_format:H:i'],
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_email' => ['nullable', 'email', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $locationId = (int) $validated['location_id'];

        if (! $this->canAccessLocation($request, $tenantId, $locationId)) {
            throw ValidationException::withMessages([
                'location_id' => 'Du har ikke adgang til den valgte lokation.',
            ]);
        }

        $location = Location::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->find($locationId);

        if (! $location) {
            throw ValidationException::withMessages([
                'location_id' => 'Lokationen kunne ikke findes.',
            ]);
        }

        $staffMember = User::query()
            ->where('tenant_id', $tenantId)
            ->bookable()
            ->whereKey((int) $validated['staff_user_id'])
            ->whereHas('locations', function (Builder $query) use ($locationId): void {
                $query->whereKey($locationId)
                    ->where('location_user.is_active', true);
            })
            ->first();

        if (! $staffMember) {
            throw ValidationException::withMessages([
                'staff_user_id' => 'Den valgte behandler er ikke tilgængelig på afdelingen.',
            ]);
        }

        $service = Service::queryForLocation($tenantId, $locationId)
            ->where('location_settings.is_active', true)
            ->whereKey((int) $validated['service_id'])
            ->first();

        if (! $service) {
            throw ValidationException::withMessages([
                'service_id' => 'Den valgte ydelse er ikke tilgængelig på den valgte lokation.',
            ]);
        }

        if (
            ! $this->userCanBookService($staffMember, (int) $service->id, $locationId)
        ) {
            throw ValidationException::withMessages([
                'service_id' => 'Den valgte ydelse er ikke tilknyttet behandleren.',
            ]);
        }

        $durationMinutes = $service->effectiveDurationMinutes();
        $bufferBeforeMinutes = $service->bufferBeforeMinutes();
        $bufferAfterMinutes = $service->bufferAfterMinutes();

        if ($durationMinutes < 15) {
            throw ValidationException::withMessages([
                'service_id' => 'Ydelsen mangler en gyldig varighed.',
            ]);
        }

        $locationTimezone = $this->resolveTimezone($location->timezone);
        $startsAt = CarbonImmutable::createFromFormat(
            'Y-m-d H:i',
            $validated['booking_date'] . ' ' . $validated['booking_time'],
            $locationTimezone
        );
        $endsAt = $startsAt->addMinutes($durationMinutes);
        $blockedInterval = $this->resolveBlockedInterval(
            $startsAt,
            $endsAt,
            $bufferBeforeMinutes,
            $bufferAfterMinutes
        );

        if (! $this->startsOnQuarterHour($startsAt)) {
            throw ValidationException::withMessages([
                'booking_time' => 'Vælg et tidspunkt på et kvarter, fx 09:00, 09:15 eller 09:30.',
            ]);
        }

        if (! $availability->allowsInterval(
            (int) $location->id,
            $blockedInterval['starts_at'],
            $blockedInterval['ends_at']
        )) {
            throw ValidationException::withMessages([
                'booking_time' => 'Tiden ligger uden for åbningstid/undtagelser for lokationen.',
            ]);
        }

        if (
            ! $shiftAvailability->userCoversIntervalForDate(
                $tenantId,
                (int) $location->id,
                (int) $staffMember->id,
                $blockedInterval['starts_at'],
                $blockedInterval['ends_at']
            )
        ) {
            throw ValidationException::withMessages([
                'booking_time' => 'Behandleren er ikke vagtplaneret i det valgte tidsrum.',
            ]);
        }

        if ($slotManager->hasConflict(
            $tenantId,
            (int) $staffMember->id,
            $blockedInterval['starts_at'],
            $blockedInterval['ends_at']
        )) {
            throw ValidationException::withMessages([
                'booking_time' => 'Det valgte tidspunkt er ikke ledigt.',
            ]);
        }

        $customer = $this->resolveCustomerForCreate($validated, $tenantId);

        $booking = DB::transaction(function () use (
            $tenantId,
            $location,
            $customer,
            $service,
            $staffMember,
            $startsAt,
            $endsAt,
            $validated,
            $slotManager,
            $bufferBeforeMinutes,
            $bufferAfterMinutes
        ): Booking {
            $booking = Booking::query()->create([
                'tenant_id' => $tenantId,
                'location_id' => (int) $location->id,
                'customer_id' => (int) $customer->id,
                'service_id' => (int) $service->id,
                'staff_user_id' => (int) $staffMember->id,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'buffer_before_minutes' => $bufferBeforeMinutes,
                'buffer_after_minutes' => $bufferAfterMinutes,
                'status' => Booking::STATUS_CONFIRMED,
                'notes' => filled($validated['notes'] ?? null) ? trim((string) $validated['notes']) : null,
            ]);

            $this->syncBookingSlots($slotManager, $booking, 'booking_time');

            return $booking;
        });

        try {
            $smsNotifier->sendConfirmation($booking);
        } catch (Throwable $exception) {
            report($exception);
        }

        $activityLogger->logBookingCreated($actor, $booking->fresh());

        $booking->load($this->bookingRelations());

        return response()->json([
            'message' => 'Bookingen er oprettet.',
            'booking' => $this->bookingPayload($booking),
        ], 201);
    }

    private function resolveDate(string $value, string $timezone): CarbonImmutable
    {
        $trimmed = trim($value);

        if ($trimmed !== '') {
            try {
                return CarbonImmutable::createFromFormat('Y-m-d', $trimmed, $timezone)->startOfDay();
            } catch (Throwable) {
                //
            }
        }

        return CarbonImmutable::now($timezone)->startOfDay();
    }

    private function normalizeStatus(string $status): string
    {
        return in_array($status, ['active', 'all', 'confirmed', 'completed', 'canceled'], true)
            ? $status
            : 'active';
    }

    /**
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function locationsFor(Request $request, int $tenantId)
    {
        return $this->locationScopeForRequest($request, $tenantId)
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'timezone', 'city'])
            ->map(fn (Location $location): array => [
                'id' => (int) $location->id,
                'name' => (string) $location->name,
                'slug' => (string) $location->slug,
                'timezone' => (string) $location->timezone,
                'city' => $location->city,
            ]);
    }

    /**
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function servicesFor(int $tenantId, int $locationId)
    {
        return Service::queryForLocation($tenantId, $locationId)
            ->where('location_settings.is_active', true)
            ->orderBy('service_categories.sort_order')
            ->orderBy('service_categories.name')
            ->orderByRaw('COALESCE(location_settings.sort_order, services.sort_order)')
            ->orderBy('services.name')
            ->get()
            ->map(fn (Service $service): array => [
                'id' => (int) $service->id,
                'name' => (string) ($service->getAttribute('location_name') ?: $service->name),
                'category' => (string) $service->category_name,
                'duration_minutes' => $service->effectiveDurationMinutes(),
                'price_minor' => $service->effectivePriceMinor(),
                'color' => (string) ($service->getAttribute('location_color') ?: $service->color ?: '#5E7097'),
                'online_bookable' => (bool) $service->is_online_bookable,
            ])
            ->values();
    }

    /**
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function staffFor(int $tenantId, int $locationId)
    {
        return User::query()
            ->where('tenant_id', $tenantId)
            ->whereHas('locations', function (Builder $query) use ($locationId): void {
                $query->whereKey($locationId)
                    ->where('location_user.is_active', true);
            })
            ->bookable()
            ->orderBy('name')
            ->get(['id', 'name', 'initials', 'profile_photo_path', 'tenant_id'])
            ->map(fn (User $user): array => [
                'id' => (int) $user->id,
                'name' => (string) $user->name,
                'initials' => $user->bookingInitials(),
                'profile_photo_url' => $user->profilePhotoUrl(),
            ])
            ->values();
    }

    /**
     * @param list<int> $activeServiceIds
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function staffForBookingOptions(
        int $tenantId,
        int $locationId,
        CarbonImmutable $date,
        array $activeServiceIds
    ) {
        if ($activeServiceIds === []) {
            return collect();
        }

        return User::query()
            ->where('tenant_id', $tenantId)
            ->whereHas('locations', function (Builder $query) use ($locationId): void {
                $query->whereKey($locationId)
                    ->where('location_user.is_active', true);
            })
            ->whereHas('workShifts', function (Builder $query) use ($tenantId, $locationId, $date): void {
                $query
                    ->where('tenant_id', $tenantId)
                    ->where('location_id', $locationId)
                    ->whereDate('shift_date', $date->toDateString())
                    ->where('work_role', UserWorkShift::ROLE_SERVICE);
            })
            ->bookable()
            ->orderBy('name')
            ->get(['id', 'name', 'initials', 'profile_photo_path', 'tenant_id', 'competency_scope'])
            ->map(function (User $user) use ($activeServiceIds, $locationId): array {
                $serviceIds = array_values(array_intersect(
                    $activeServiceIds,
                    $this->serviceIdsForStaff($user, $locationId)
                ));

                return [
                    'id' => (int) $user->id,
                    'name' => (string) $user->name,
                    'initials' => $user->bookingInitials(),
                    'profile_photo_url' => $user->profilePhotoUrl(),
                    'service_ids' => $serviceIds,
                ];
            })
            ->filter(static fn (array $staff): bool => $staff['service_ids'] !== [])
            ->values();
    }

    private function hasWorkShiftForDate(
        int $tenantId,
        int $locationId,
        int $userId,
        CarbonImmutable $date
    ): bool {
        if (! $this->isWorkShiftsEnabledForTenant($tenantId)) {
            return true;
        }

        if ($tenantId <= 0 || $locationId <= 0 || $userId <= 0) {
            return false;
        }

        return UserWorkShift::query()
            ->where('tenant_id', $tenantId)
            ->where('location_id', $locationId)
            ->where('user_id', $userId)
            ->whereDate('shift_date', $date->toDateString())
            ->where('work_role', UserWorkShift::ROLE_SERVICE)
            ->exists();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function workShiftLocationForDate(
        Request $request,
        int $tenantId,
        int $userId,
        CarbonImmutable $date
    ): ?array {
        if (! $this->isWorkShiftsEnabledForTenant($tenantId) || $tenantId <= 0 || $userId <= 0) {
            return null;
        }

        $locationIds = UserWorkShift::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->whereDate('shift_date', $date->toDateString())
            ->where('work_role', UserWorkShift::ROLE_SERVICE)
            ->orderBy('starts_at')
            ->pluck('location_id')
            ->map(static fn (int $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($locationIds === []) {
            return null;
        }

        $locations = $this->locationScopeForRequest($request, $tenantId)
            ->whereIn('id', $locationIds)
            ->get(['id', 'name', 'slug', 'timezone', 'city']);

        $location = $locations
            ->sortBy(function (Location $location) use ($locationIds): int {
                $position = array_search((int) $location->id, $locationIds, true);

                return $position === false ? PHP_INT_MAX : $position;
            })
            ->first();

        if (! $location) {
            return null;
        }

        return [
            'id' => (int) $location->id,
            'name' => (string) $location->name,
            'slug' => (string) $location->slug,
            'timezone' => (string) $location->timezone,
            'city' => $location->city,
        ];
    }

    /**
     * @return array{start_minutes: int, end_minutes: int, opening_intervals: list<array{start_minutes: int, end_minutes: int}>, work_shift_intervals: list<array{start_minutes: int, end_minutes: int}>}|null
     */
    private function calendarGridForDate(
        LocationAvailability $availability,
        WorkShiftAvailability $shiftAvailability,
        int $tenantId,
        int $locationId,
        int $userId,
        CarbonImmutable $date
    ): ?array {
        $openingIntervals = $availability->intervalsForDate($locationId, $date->startOfDay())
            ->map(function (array $interval): ?array {
                $startMinutes = $this->minuteOfDayFromTime((string) ($interval['opens_at'] ?? '00:00'));
                $endMinutes = $this->minuteOfDayFromTime((string) ($interval['closes_at'] ?? '00:00'));

                if ($endMinutes <= $startMinutes) {
                    return null;
                }

                return [
                    'start_minutes' => $startMinutes,
                    'end_minutes' => $endMinutes,
                ];
            })
            ->filter()
            ->values();

        if ($openingIntervals->isEmpty()) {
            return null;
        }

        $openingIntervalRows = $openingIntervals->all();
        $workShiftIntervals = $this->isWorkShiftsEnabledForTenant($tenantId)
            ? collect($shiftAvailability->coverageByUserForDate(
                $tenantId,
                $locationId,
                $date->startOfDay(),
                [$userId],
                false
            )[$userId] ?? [])
                ->map(static fn (array $segment): array => [
                    'start_minutes' => (int) $segment['start'],
                    'end_minutes' => (int) $segment['end'],
                ])
                ->values()
                ->all()
            : $openingIntervalRows;

        return [
            'start_minutes' => (int) collect($openingIntervalRows)->min('start_minutes'),
            'end_minutes' => (int) collect($openingIntervalRows)->max('end_minutes'),
            'opening_intervals' => $openingIntervalRows,
            'work_shift_intervals' => $workShiftIntervals,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function nextWorkShiftForUser(
        Request $request,
        int $tenantId,
        int $userId,
        string $fallbackTimezone
    ): ?array {
        if (! $this->isWorkShiftsEnabledForTenant($tenantId) || $tenantId <= 0 || $userId <= 0) {
            return null;
        }

        $accessibleLocationIds = $this->resolveAccessibleLocationIds($request, $tenantId);

        if ($accessibleLocationIds === []) {
            return null;
        }

        $timezone = $this->resolveTimezone((string) (Tenant::query()
            ->whereKey($tenantId)
            ->value('timezone') ?: $fallbackTimezone));
        $now = CarbonImmutable::now($timezone);
        $todayDate = $now->toDateString();
        $currentTime = $now->format('H:i:s');

        $upcomingShifts = UserWorkShift::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->whereIn('location_id', $accessibleLocationIds)
            ->where(function (Builder $query) use ($todayDate, $currentTime): void {
                $query
                    ->whereDate('shift_date', '>', $todayDate)
                    ->orWhere(function (Builder $todayQuery) use ($todayDate, $currentTime): void {
                        $todayQuery
                            ->whereDate('shift_date', '=', $todayDate)
                            ->whereTime('ends_at', '>', $currentTime);
                    });
            })
            ->with(['location:id,name,slug,timezone,city'])
            ->orderBy('shift_date')
            ->orderBy('starts_at')
            ->limit(20)
            ->get([
                'id',
                'tenant_id',
                'location_id',
                'user_id',
                'shift_date',
                'starts_at',
                'ends_at',
                'work_role',
            ]);

        if ($upcomingShifts->isEmpty()) {
            return null;
        }

        $nextShift = $upcomingShifts->first(function (UserWorkShift $shift) use ($now, $timezone): bool {
            return $this->workShiftDateTime($shift, (string) $shift->starts_at, $timezone)->greaterThan($now);
        });
        $countdownLabel = 'Næste vagt om';

        if (! $nextShift instanceof UserWorkShift) {
            $nextShift = $upcomingShifts->first();
            $countdownLabel = 'Aktuel vagt slutter om';
        }

        if (! $nextShift instanceof UserWorkShift) {
            return null;
        }

        $startsAt = $this->workShiftDateTime($nextShift, (string) $nextShift->starts_at, $timezone);
        $endsAt = $this->workShiftDateTime($nextShift, (string) $nextShift->ends_at, $timezone);
        $countdownTarget = $countdownLabel === 'Næste vagt om' ? $startsAt : $endsAt;
        $location = $nextShift->location;

        return [
            'id' => (int) $nextShift->id,
            'starts_at' => $startsAt->toIso8601String(),
            'ends_at' => $endsAt->toIso8601String(),
            'time_range' => $startsAt->format('H:i').' - '.$endsAt->format('H:i'),
            'work_role' => $nextShift->workRoleValue(),
            'work_role_label' => $nextShift->workRoleLabel(),
            'countdown_label' => $countdownLabel,
            'countdown_target' => $countdownTarget->toIso8601String(),
            'location' => $location ? [
                'id' => (int) $location->id,
                'name' => (string) $location->name,
                'slug' => (string) $location->slug,
                'timezone' => (string) $location->timezone,
                'city' => $location->city,
            ] : null,
        ];
    }

    private function workShiftDateTime(UserWorkShift $shift, string $time, string $timezone): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat(
            'Y-m-d H:i:s',
            $shift->shift_date->format('Y-m-d').' '.$time,
            $timezone
        );
    }

    private function userCanBookService(User $user, int $serviceId, int $locationId): bool
    {
        return in_array($serviceId, $this->serviceIdsForStaff($user, $locationId), true);
    }

    /**
     * @return list<int>
     */
    private function serviceIdsForStaff(User $user, int $locationId): array
    {
        $query = $user->usesLocationCompetencies()
            ? DB::table('location_service_user')
                ->where('user_id', $user->id)
                ->where('location_id', $locationId)
            : DB::table('service_user')
                ->where('user_id', $user->id);

        return $query
            ->pluck('service_id')
            ->map(static fn (int $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function minuteOfDayFromTime(string $time): int
    {
        $parts = explode(':', $time);
        $hours = (int) ($parts[0] ?? 0);
        $minutes = (int) ($parts[1] ?? 0);

        return max(0, min((24 * 60), ($hours * 60) + $minutes));
    }

    private function startsOnQuarterHour(CarbonImmutable $startsAt): bool
    {
        return in_array((int) $startsAt->format('i'), [0, 15, 30, 45], true);
    }

    /**
     * @return array{starts_at: CarbonImmutable, ends_at: CarbonImmutable}
     */
    private function resolveBlockedInterval(
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        int $bufferBeforeMinutes,
        int $bufferAfterMinutes
    ): array {
        return [
            'starts_at' => $startsAt->subMinutes(max(0, $bufferBeforeMinutes)),
            'ends_at' => $endsAt->addMinutes(max(0, $bufferAfterMinutes)),
        ];
    }

    private function resolveCustomerForCreate(array $validated, int $tenantId): Customer
    {
        $name = trim((string) $validated['customer_name']);
        $email = filled($validated['customer_email'] ?? null)
            ? strtolower(trim((string) $validated['customer_email']))
            : null;
        $phone = filled($validated['customer_phone'] ?? null)
            ? trim((string) $validated['customer_phone'])
            : null;

        if ($email !== null) {
            $customer = Customer::query()->firstOrNew([
                'tenant_id' => $tenantId,
                'email' => $email,
            ]);
            $customer->name = $name;

            if ($phone !== null) {
                $customer->phone = $phone;
            }

            $customer->save();

            return $customer;
        }

        return Customer::query()->create([
            'tenant_id' => $tenantId,
            'name' => $name,
            'email' => null,
            'phone' => $phone,
        ]);
    }

    private function resolveTimezone(?string $timezone): string
    {
        $candidate = is_string($timezone) ? trim($timezone) : '';

        return $candidate !== ''
            ? $candidate
            : (string) config('app.timezone', 'UTC');
    }

    private function syncBookingSlots(
        BookingSlotManager $slotManager,
        Booking $booking,
        string $fieldName
    ): void {
        try {
            $slotManager->syncSlotsForBooking($booking);
        } catch (QueryException $exception) {
            if ($this->isSlotConflictException($exception)) {
                throw ValidationException::withMessages([
                    $fieldName => 'Tiden blev netop optaget. Prøv et andet tidspunkt.',
                ]);
            }

            throw $exception;
        }
    }

    private function isSlotConflictException(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? '');
        $driverErrorCode = (int) ($exception->errorInfo[1] ?? 0);

        if ($sqlState !== '23000') {
            return false;
        }

        return in_array($driverErrorCode, [0, 19, 1062], true);
    }

    private function isWorkShiftsEnabledForTenant(int $tenantId): bool
    {
        return (bool) (Tenant::query()
            ->whereKey($tenantId)
            ->value('work_shifts_enabled') ?? true);
    }

    /**
     * @return array<string, mixed>
     */
    private function bookingPayload(Booking $booking): array
    {
        return [
            'id' => (int) $booking->id,
            'customer' => $booking->customer?->name ?? 'Ukendt kunde',
            'customer_email' => $booking->customer?->email,
            'customer_phone' => $booking->customer?->phone,
            'service' => $booking->service?->name ?? 'Booking',
            'service_color' => $booking->service?->color ?: '#5E7097',
            'staff_name' => $booking->staffMember?->name ?? 'Ukendt medarbejder',
            'location_name' => $booking->location?->name ?? '',
            'starts_at' => $booking->starts_at?->toIso8601String(),
            'ends_at' => $booking->ends_at?->toIso8601String(),
            'time_range' => $booking->starts_at?->format('H:i') . ' - ' . $booking->ends_at?->format('H:i'),
            'status' => (string) $booking->status,
            'notes' => $booking->notes,
        ];
    }

    /**
     * @return list<string>
     */
    private function bookingRelations(): array
    {
        return [
            'customer:id,name,email,phone',
            'service:id,name,color,duration_minutes,price_minor',
            'staffMember:id,name,initials,profile_photo_path',
            'location:id,name',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function userPayload(User $user): array
    {
        return [
            'id' => (int) $user->id,
            'name' => (string) $user->name,
            'email' => (string) $user->email,
            'initials' => $user->bookingInitials(),
            'role_label' => $user->roleLabel(),
            'profile_photo_url' => $user->profilePhotoUrl(),
        ];
    }
}
