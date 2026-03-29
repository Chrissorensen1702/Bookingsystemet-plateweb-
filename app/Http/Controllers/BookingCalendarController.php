<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Location;
use App\Models\LocationOpeningHour;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Support\BookingSlotManager;
use App\Support\LocationAvailability;
use App\Support\WorkShiftAvailability;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class BookingCalendarController extends Controller
{
    public function __invoke(
        Request $request,
        LocationAvailability $availability,
        BookingSlotManager $slotManager,
        WorkShiftAvailability $shiftAvailability
    ): View
    {
        $tenantId = $this->resolveTenantId($request);
        abort_if($tenantId <= 0, 500, 'Ingen aktiv tenant er konfigureret.');
        $workShiftsEnabled = $this->isWorkShiftsEnabledForTenant($tenantId);
        $locationIdFilter = $this->resolveLocationId($request, $tenantId);
        abort_if($locationIdFilter <= 0, 500, 'Ingen aktiv lokation er konfigureret.');

        $tenantTimezone = Tenant::query()
            ->whereKey($tenantId)
            ->value('timezone');
        $selectedLocationTimezone = Location::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($locationIdFilter)
            ->value('timezone');
        $calendarTimezone = $this->resolveCalendarTimezone(
            is_string($selectedLocationTimezone) ? $selectedLocationTimezone : null,
            is_string($tenantTimezone) ? $tenantTimezone : null
        );

        $selectedDate = $this->resolveSelectedDate(
            (string) $request->query('date', (string) $request->query('week', '')),
            $calendarTimezone
        );
        $statusFilter = $this->normalizeStatusFilter((string) $request->query('status', 'active'));
        $staffUserIdFilter = max(
            0,
            (int) $request->query('staff_user_id', $request->query('staff_member_id', 0))
        );
        $serviceIdFilter = max(0, (int) $request->query('service_id', 0));
        $selectedBookingId = max(0, (int) $request->query('selected_booking', 0));

        $this->autoCompleteExpiredBookings($tenantId, $locationIdFilter, $calendarTimezone);

        $staffMembers = User::query()
            ->where('tenant_id', $tenantId)
            ->whereHas('locations', function (Builder $query) use ($locationIdFilter): void {
                $query->whereKey($locationIdFilter)
                    ->where('location_user.is_active', true);
            })
            ->bookable()
            ->orderBy('name')
            ->get(['id', 'name', 'initials', 'profile_photo_path']);
        $staffIdsAtLocation = $staffMembers
            ->pluck('id')
            ->map(static fn (int $id): int => $id)
            ->all();
        $visibleStaffMembers = $staffUserIdFilter > 0
            ? $staffMembers->where('id', $staffUserIdFilter)->values()
            : $staffMembers->values();
        $staffColumns = [];
        $staffColumnsById = [];

        foreach ($visibleStaffMembers as $index => $staffMember) {
            $column = $index + 2;
            $staffColumns[] = [
                'id' => (int) $staffMember->id,
                'name' => (string) $staffMember->name,
                'initials' => $staffMember->bookingInitials(),
                'photo_url' => $staffMember->profilePhotoUrl(),
                'column' => $column,
            ];
            $staffColumnsById[(int) $staffMember->id] = $column;
        }

        $visibleStaffIds = array_keys($staffColumnsById);

        $bookingQuery = Booking::query()
            ->with([
                'customer:id,name,email,phone',
                'service:id,name,color,duration_minutes',
                'staffMember:id,name,initials,profile_photo_path',
                'location:id,name',
                'completedBy:id,name',
            ])
            ->where('tenant_id', $tenantId)
            ->where('location_id', $locationIdFilter)
            ->whereBetween('starts_at', [
                $selectedDate->startOfDay(),
                $selectedDate->endOfDay(),
            ]);

        if ($staffUserIdFilter > 0) {
            $bookingQuery->where('staff_user_id', $staffUserIdFilter);
        }

        if ($serviceIdFilter > 0) {
            $bookingQuery->where('service_id', $serviceIdFilter);
        }

        $this->applyStatusFilter($bookingQuery, $statusFilter);

        $bookingModels = $bookingQuery
            ->orderBy('starts_at')
            ->get();

        $slotBounds = $this->resolveCalendarSlotBounds(
            $availability,
            $locationIdFilter,
            [$selectedDate->toDateString()],
            $bookingModels,
            $calendarTimezone
        );
        $timeSlots = $this->buildTimeSlots(
            $slotBounds['slot_start_minutes'],
            $slotBounds['slot_end_minutes']
        );
        $slotRows = [];

        foreach ($timeSlots as $index => $time) {
            $slotRows[$time] = $index + 2;
        }

        $openGridSlotsByStaff = $this->resolveOpenGridSlotsByStaff(
            $availability,
            $slotManager,
            $shiftAvailability,
            $tenantId,
            $locationIdFilter,
            $selectedDate,
            $timeSlots,
            $visibleStaffIds,
            $workShiftsEnabled
        );

        $selectionBaseQuery = $this->normalizeQuery([
            'date' => $selectedDate->toDateString(),
            'location_id' => $locationIdFilter,
            'status' => $statusFilter === 'active' ? null : $statusFilter,
            'staff_user_id' => $staffUserIdFilter > 0 ? $staffUserIdFilter : null,
            'service_id' => $serviceIdFilter > 0 ? $serviceIdFilter : null,
        ]);

        $calendarBookings = $bookingModels
            ->map(function (Booking $booking) use ($selectedDate, $staffColumnsById, $slotRows, $selectedBookingId, $selectionBaseQuery): ?array {
                $column = $staffColumnsById[(int) $booking->staff_user_id] ?? null;
                $timeKey = $booking->starts_at->format('H:i');
                $startRow = $slotRows[$timeKey] ?? null;

                if (! is_int($column) || ! isset($startRow)) {
                    return null;
                }

                $duration = max(15, $booking->starts_at->diffInMinutes($booking->ends_at));
                $showComplete = $booking->isConfirmed();
                $canCancel = $booking->isConfirmed() || $booking->isCompleted();
                $canEdit = $booking->isConfirmed();
                $serviceColor = $this->normalizeHexColor(
                    $booking->service?->color
                );
                $backgroundOpacity = $booking->isCompleted() ? 0.22 : 0.4;

                return [
                    'id' => $booking->id,
                    'day_key' => $selectedDate->toDateString(),
                    'column' => $column,
                    'start_row' => $startRow,
                    'row_span' => max(1, (int) ceil($duration / 15)),
                    'staff_user_id' => $booking->staff_user_id,
                    'service_id' => $booking->service_id,
                    'customer' => $booking->customer?->name ?? 'Ukendt kunde',
                    'service' => $booking->service?->name ?? 'Booking',
                    'service_duration' => max(15, (int) $booking->starts_at->diffInMinutes($booking->ends_at)),
                    'status' => $booking->status,
                    'status_label' => $this->statusLabel($booking->status),
                    'time_range' => $booking->starts_at->format('H:i') . ' - ' . $booking->ends_at->format('H:i'),
                    'staff_name' => $booking->staffMember?->name ?? 'Ukendt medarbejder',
                    'location_name' => $booking->location?->name ?? 'Ukendt lokation',
                    'customer_email' => $booking->customer?->email,
                    'customer_phone' => $booking->customer?->phone,
                    'notes' => $booking->notes,
                    'compact' => $duration <= 30,
                    'can_cancel' => $canCancel,
                    'show_complete' => $showComplete,
                    'can_edit' => $canEdit,
                    'slot_background' => $this->hexToRgba($serviceColor, $backgroundOpacity),
                    'slot_border' => $this->hexToRgba($serviceColor, 0.84),
                    'slot_border_soft' => $this->hexToRgba($serviceColor, 0.5),
                    'booking_date_input' => $booking->starts_at->format('Y-m-d'),
                    'booking_time_input' => $booking->starts_at->format('H:i'),
                    'update_url' => $canEdit ? route('bookings.update', $booking->id) : '',
                    'is_completed' => $booking->isCompleted(),
                    'is_selected' => $selectedBookingId === $booking->id,
                    'select_url' => route('booking-calender', $this->normalizeQuery([
                        ...$selectionBaseQuery,
                        'selected_booking' => $booking->id,
                    ])),
                ];
            })
            ->filter()
            ->values();

        $selectedBookingModel = $selectedBookingId > 0
            ? $bookingModels->firstWhere('id', $selectedBookingId)
            : null;

        $selectedBooking = $selectedBookingModel instanceof Booking
            ? $this->selectedBookingPayload($selectedBookingModel)
            : null;

        $selectedBookingTimeOptions = $timeSlots;

        if (is_array($selectedBooking) && $selectedBookingModel instanceof Booking) {
            $selectedDateInput = trim((string) ($selectedBooking['booking_date_input'] ?? ''));
            $selectedBookingDate = null;

            if ($selectedDateInput !== '') {
                try {
                    $selectedBookingDate = CarbonImmutable::createFromFormat('Y-m-d', $selectedDateInput, $calendarTimezone)
                        ->startOfDay();
                } catch (Throwable) {
                    $selectedBookingDate = null;
                }
            }

            if ($selectedBookingDate instanceof CarbonImmutable) {
                $selectedBookingCandidateTimes = $availability->startTimesForDate(
                    $locationIdFilter,
                    $selectedBookingDate,
                    15,
                    max(15, (int) $selectedBookingModel->starts_at->diffInMinutes($selectedBookingModel->ends_at)),
                    max(0, (int) ($selectedBookingModel->buffer_before_minutes ?? 0)),
                    max(0, (int) ($selectedBookingModel->buffer_after_minutes ?? 0))
                );
                $selectedBookingTimeOptions = $this->resolveAvailableStartTimes(
                    $slotManager,
                    $shiftAvailability,
                    $tenantId,
                    $locationIdFilter,
                    $selectedBookingDate,
                    [(int) $selectedBookingModel->staff_user_id],
                    $selectedBookingCandidateTimes,
                    max(15, (int) $selectedBookingModel->starts_at->diffInMinutes($selectedBookingModel->ends_at)),
                    max(0, (int) ($selectedBookingModel->buffer_before_minutes ?? 0)),
                    max(0, (int) ($selectedBookingModel->buffer_after_minutes ?? 0)),
                    $workShiftsEnabled,
                    false
                );
            }

            $editingTime = trim((string) ($selectedBooking['booking_time_input'] ?? ''));

            if ($editingTime !== '' && ! in_array($editingTime, $selectedBookingTimeOptions, true)) {
                $selectedBookingTimeOptions[] = $editingTime;
                sort($selectedBookingTimeOptions);
            }

            if ($selectedBookingTimeOptions === []) {
                $selectedBookingTimeOptions = $timeSlots;
            }
        }

        $locations = $this->locationScopeForRequest($request, $tenantId)
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'timezone']);

        $createBookingDateInput = trim((string) old(
            'create_booking_date',
            $selectedDate->toDateString()
        ));

        try {
            $createBookingDate = CarbonImmutable::createFromFormat('Y-m-d', $createBookingDateInput, $calendarTimezone)
                ->startOfDay();
        } catch (Throwable) {
            $createBookingDate = CarbonImmutable::now($calendarTimezone)->startOfDay();
            $createBookingDateInput = $createBookingDate->toDateString();
        }

        $createService = Service::queryForLocation($tenantId, $locationIdFilter)
            ->where('location_settings.is_active', true)
            ->whereKey((int) old('create_service_id', 0))
            ->first();
        $createServiceDuration = $createService?->effectiveDurationMinutes() ?? 15;
        $createServiceBufferBefore = $createService?->bufferBeforeMinutes() ?? 0;
        $createServiceBufferAfter = $createService?->bufferAfterMinutes() ?? 0;
        $createSelectedStaffUserId = max(0, (int) old('create_staff_user_id', 0));
        $createEligibleStaffIds = $createSelectedStaffUserId > 0
            ? (in_array($createSelectedStaffUserId, $staffIdsAtLocation, true) ? [$createSelectedStaffUserId] : [])
            : $staffIdsAtLocation;

        $createBookingCandidateTimes = $availability->startTimesForDate(
            $locationIdFilter,
            $createBookingDate,
            15,
            $createServiceDuration,
            $createServiceBufferBefore,
            $createServiceBufferAfter
        );
        $createBookingTimeOptions = $this->resolveAvailableStartTimes(
            $slotManager,
            $shiftAvailability,
            $tenantId,
            $locationIdFilter,
            $createBookingDate,
            $createEligibleStaffIds,
            $createBookingCandidateTimes,
            $createServiceDuration,
            $createServiceBufferBefore,
            $createServiceBufferAfter,
            $workShiftsEnabled,
            false
        );

        $createSelectedTime = trim((string) old('create_booking_time', ''));
        if ($createSelectedTime !== '' && ! in_array($createSelectedTime, $createBookingTimeOptions, true)) {
            $createBookingTimeOptions[] = $createSelectedTime;
            sort($createBookingTimeOptions);
        }

        $serverNowUtc = now('UTC');

        $navigationBaseQuery = $this->normalizeQuery([
            'date' => $selectedDate->toDateString(),
            'location_id' => $locationIdFilter,
            'status' => $statusFilter === 'active' ? null : $statusFilter,
            'staff_user_id' => $staffUserIdFilter > 0 ? $staffUserIdFilter : null,
            'service_id' => $serviceIdFilter > 0 ? $serviceIdFilter : null,
        ]);

        return view('booking-calender', [
            'timeSlots' => $timeSlots,
            'staffColumns' => $staffColumns,
            'slotRows' => $slotRows,
            'openGridSlotsByStaff' => $openGridSlotsByStaff,
            'calendarBookings' => $calendarBookings,
            'locations' => $locations,
            'staffMembers' => $staffMembers,
            'services' => Service::queryForLocation($tenantId, $locationIdFilter)
                ->where('location_settings.is_active', true)
                ->orderByRaw('COALESCE(location_settings.sort_order, services.sort_order)')
                ->orderBy('services.name')
                ->orderBy('service_categories.name')
                ->get(['services.id', 'services.name', 'services.service_category_id', 'service_categories.name as category_name']),
            'timeOptions' => $selectedBookingTimeOptions,
            'createBooking' => [
                'date_input' => $createBookingDateInput,
                'time_options' => $createBookingTimeOptions,
                'selected_time' => $createSelectedTime,
            ],
            'selectedBooking' => $selectedBooking,
            'filterState' => [
                'location_id' => $locationIdFilter,
                'status' => $statusFilter,
                'staff_user_id' => $staffUserIdFilter,
                'service_id' => $serviceIdFilter,
            ],
            'selectedDateIso' => $selectedDate->toDateString(),
            'previousDateUrl' => route('booking-calender', $this->normalizeQuery([
                ...$navigationBaseQuery,
                'date' => $selectedDate->subDay()->toDateString(),
            ])),
            'nextDateUrl' => route('booking-calender', $this->normalizeQuery([
                ...$navigationBaseQuery,
                'date' => $selectedDate->addDay()->toDateString(),
            ])),
            'todayDateUrl' => route('booking-calender', $this->normalizeQuery([
                ...$navigationBaseQuery,
                'date' => CarbonImmutable::now($calendarTimezone)->toDateString(),
            ])),
            'clearFiltersUrl' => route('booking-calender', [
                'date' => $selectedDate->toDateString(),
                'location_id' => $locationIdFilter,
            ]),
            'nowIndicator' => [
                'timezone' => $calendarTimezone,
                'server_now_utc_ms' => (int) $serverNowUtc->valueOf(),
                'slot_start_minutes' => $slotBounds['slot_start_minutes'],
                'slot_end_minutes' => $slotBounds['slot_end_minutes'],
            ],
        ]);
    }

    public function timeOptions(
        Request $request,
        LocationAvailability $availability,
        BookingSlotManager $slotManager,
        WorkShiftAvailability $shiftAvailability
    ): JsonResponse
    {
        $tenantId = $this->resolveTenantId($request);
        abort_if($tenantId <= 0, 500, 'Ingen aktiv tenant er konfigureret.');
        $workShiftsEnabled = $this->isWorkShiftsEnabledForTenant($tenantId);

        $validated = $request->validate([
            'booking_date' => ['required', 'date'],
            'location_id' => ['nullable', 'integer'],
            'service_id' => ['nullable', 'integer'],
            'staff_user_id' => ['nullable', 'integer'],
        ]);

        $locationId = max(0, (int) ($validated['location_id'] ?? 0));

        if ($locationId <= 0 || ! $this->canAccessLocation($request, $tenantId, $locationId)) {
            $locationId = $this->resolveLocationId($request, $tenantId);
        }

        abort_if($locationId <= 0, 404, 'Ingen aktiv lokation fundet.');

        try {
            $locationTimezone = Location::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($locationId)
                ->value('timezone');
            $calendarTimezone = $this->resolveCalendarTimezone(
                is_string($locationTimezone) ? $locationTimezone : null,
                null
            );

            $date = CarbonImmutable::createFromFormat('Y-m-d', (string) $validated['booking_date'], $calendarTimezone)
                ->startOfDay();
        } catch (Throwable) {
            $date = CarbonImmutable::now($calendarTimezone ?? 'UTC')->startOfDay();
        }

        $serviceId = max(0, (int) ($validated['service_id'] ?? 0));
        $serviceDurationMinutes = 15;
        $serviceBufferBeforeMinutes = 0;
        $serviceBufferAfterMinutes = 0;

        if ($serviceId > 0) {
            $service = Service::queryForLocation($tenantId, $locationId)
                ->where('location_settings.is_active', true)
                ->whereKey($serviceId)
                ->first();

            if (! $service) {
                return response()->json([
                    'time_options' => [],
                ]);
            }

            $serviceDurationMinutes = $service->effectiveDurationMinutes();
            $serviceBufferBeforeMinutes = $service->bufferBeforeMinutes();
            $serviceBufferAfterMinutes = $service->bufferAfterMinutes();
        }

        $staffUserId = max(0, (int) ($validated['staff_user_id'] ?? 0));
        $staffIdsAtLocation = User::query()
            ->where('tenant_id', $tenantId)
            ->whereHas('locations', function (Builder $query) use ($locationId): void {
                $query->whereKey($locationId)
                    ->where('location_user.is_active', true);
            })
            ->bookable()
            ->pluck('id')
            ->map(static fn (int $id): int => $id)
            ->all();
        $eligibleStaffIds = $staffUserId > 0
            ? (in_array($staffUserId, $staffIdsAtLocation, true) ? [$staffUserId] : [])
            : $staffIdsAtLocation;
        $candidateTimeOptions = $availability->startTimesForDate(
            $locationId,
            $date,
            15,
            $serviceDurationMinutes,
            $serviceBufferBeforeMinutes,
            $serviceBufferAfterMinutes
        );
        $timeOptions = $this->resolveAvailableStartTimes(
            $slotManager,
            $shiftAvailability,
            $tenantId,
            $locationId,
            $date,
            $eligibleStaffIds,
            $candidateTimeOptions,
            $serviceDurationMinutes,
            $serviceBufferBeforeMinutes,
            $serviceBufferAfterMinutes,
            $workShiftsEnabled,
            false
        );

        return response()->json([
            'time_options' => $timeOptions,
        ]);
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            Booking::STATUS_CONFIRMED => 'Bekræftet',
            Booking::STATUS_COMPLETED => 'Gennemført',
            Booking::STATUS_CANCELED => 'Annulleret',
            default => ucfirst($status),
        };
    }

    private function resolveSelectedDate(string $rawDate, string $timezone): CarbonImmutable
    {
        if ($rawDate === '') {
            return CarbonImmutable::now($timezone)->startOfDay();
        }

        try {
            return CarbonImmutable::createFromFormat('Y-m-d', $rawDate, $timezone)
                ->startOfDay();
        } catch (Throwable) {
            return CarbonImmutable::now($timezone)->startOfDay();
        }
    }

    private function normalizeStatusFilter(string $status): string
    {
        $allowed = ['active', 'all', Booking::STATUS_CONFIRMED, Booking::STATUS_COMPLETED, Booking::STATUS_CANCELED];

        return in_array($status, $allowed, true)
            ? $status
            : 'active';
    }

    private function applyStatusFilter(Builder $query, string $statusFilter): void
    {
        if ($statusFilter === 'all') {
            return;
        }

        if ($statusFilter === 'active') {
            $query->whereIn('status', [Booking::STATUS_CONFIRMED, Booking::STATUS_COMPLETED]);

            return;
        }

        $query->where('status', $statusFilter);
    }

    private function normalizeQuery(array $query): array
    {
        return collect($query)
            ->filter(static fn (mixed $value): bool => ! ($value === null || $value === ''))
            ->all();
    }

    /**
     * @param list<string> $timeSlots
     * @param list<int> $staffUserIds
     * @return array<int, array<string, bool>>
     */
    private function resolveOpenGridSlotsByStaff(
        LocationAvailability $availability,
        BookingSlotManager $slotManager,
        WorkShiftAvailability $shiftAvailability,
        int $tenantId,
        int $locationId,
        CarbonImmutable $selectedDate,
        array $timeSlots,
        array $staffUserIds,
        bool $enforceWorkShifts
    ): array {
        $openSlotsByStaff = [];
        $staffUserIds = collect($staffUserIds)
            ->map(static fn (int $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($staffUserIds === []) {
            return $openSlotsByStaff;
        }

        $dayKey = $selectedDate->toDateString();
        $intervals = $availability->intervalsForDate($locationId, $selectedDate->startOfDay());

        if ($intervals->isEmpty()) {
            return $openSlotsByStaff;
        }

        $rangeStart = $selectedDate->startOfDay();
        $rangeEnd = $selectedDate->endOfDay();
        $shiftCoverageByUser = $enforceWorkShifts
            ? $shiftAvailability->coverageByUserForDate(
                $tenantId,
                $locationId,
                $selectedDate,
                $staffUserIds,
                false
            )
            : [];

        foreach ($staffUserIds as $staffUserId) {
            $openSlotsByStaff[$staffUserId] = [];
            $occupiedCountsByDayAndTime = $slotManager->occupiedStaffCountsByDayAndTime(
                $tenantId,
                [$staffUserId],
                $rangeStart,
                $rangeEnd
            );
            $occupiedCountsByTime = $occupiedCountsByDayAndTime[$dayKey] ?? [];

            foreach ($timeSlots as $timeSlot) {
                $slotStart = $this->minuteOfDayFromTime($timeSlot);
                $slotEnd = $slotStart + 15;
                $isWithinOpening = false;

                foreach ($intervals as $interval) {
                    $opensAt = $this->minuteOfDayFromTime((string) ($interval['opens_at'] ?? '00:00'));
                    $closesAt = $this->minuteOfDayFromTime((string) ($interval['closes_at'] ?? '00:00'));
                    if ($slotStart >= $opensAt && $slotEnd <= $closesAt) {
                        $isWithinOpening = true;
                        break;
                    }
                }

                if (! $isWithinOpening) {
                    continue;
                }

                if (
                    $enforceWorkShifts &&
                    ! $shiftAvailability->userCoversMinuteInterval(
                        $shiftCoverageByUser,
                        $staffUserId,
                        $slotStart,
                        $slotEnd
                    )
                ) {
                    continue;
                }

                $occupiedStaffCount = (int) ($occupiedCountsByTime[$timeSlot] ?? 0);
                $openSlotsByStaff[$staffUserId][$timeSlot] = $occupiedStaffCount < 1;
            }
        }

        return $openSlotsByStaff;
    }

    /**
     * @param list<int> $staffUserIds
     * @param list<string> $candidateTimeOptions
     * @return list<string>
     */
    private function resolveAvailableStartTimes(
        BookingSlotManager $slotManager,
        WorkShiftAvailability $shiftAvailability,
        int $tenantId,
        int $locationId,
        CarbonImmutable $date,
        array $staffUserIds,
        array $candidateTimeOptions,
        int $requiredDurationMinutes,
        int $bufferBeforeMinutes,
        int $bufferAfterMinutes,
        bool $enforceWorkShifts,
        bool $requirePublicShift
    ): array {
        $staffUserIds = collect($staffUserIds)
            ->map(static fn (int $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        $candidateTimeOptions = collect($candidateTimeOptions)
            ->map(static fn (string $time): string => trim($time))
            ->filter(static fn (string $time): bool => preg_match('/^\d{2}:\d{2}$/', $time) === 1)
            ->unique()
            ->values()
            ->all();

        if ($staffUserIds === [] || $candidateTimeOptions === []) {
            return [];
        }

        if (! $enforceWorkShifts) {
            return $slotManager->availableStartTimesForDate(
                $tenantId,
                $locationId,
                $date,
                $staffUserIds,
                $candidateTimeOptions,
                $requiredDurationMinutes,
                $bufferBeforeMinutes,
                $bufferAfterMinutes
            );
        }

        $availableStaffByTime = $slotManager->availableStaffByStartTimeForDate(
            $tenantId,
            $locationId,
            $date,
            $staffUserIds,
            $candidateTimeOptions,
            $requiredDurationMinutes,
            $bufferBeforeMinutes,
            $bufferAfterMinutes
        );
        $shiftCoverageByUser = $shiftAvailability->coverageByUserForDate(
            $tenantId,
            $locationId,
            $date,
            $staffUserIds,
            $requirePublicShift
        );
        $availableTimes = [];

        foreach ($candidateTimeOptions as $timeOption) {
            $freeStaffIds = $availableStaffByTime[$timeOption] ?? [];

            if ($freeStaffIds === []) {
                continue;
            }

            $startMinute = $this->minuteOfDayFromTime($timeOption);
            $blockedStart = $startMinute - max(0, $bufferBeforeMinutes);
            $blockedEnd = $startMinute + max(15, $requiredDurationMinutes) + max(0, $bufferAfterMinutes);
            $hasShiftCoverage = false;

            foreach ($freeStaffIds as $staffUserId) {
                if ($shiftAvailability->userCoversMinuteInterval(
                    $shiftCoverageByUser,
                    (int) $staffUserId,
                    $blockedStart,
                    $blockedEnd
                )) {
                    $hasShiftCoverage = true;
                    break;
                }
            }

            if ($hasShiftCoverage) {
                $availableTimes[] = $timeOption;
            }
        }

        return $availableTimes;
    }

    /**
     * @param list<string> $dateKeys
     * @param \Illuminate\Support\Collection<int, Booking> $bookingModels
     * @return array{slot_start_minutes: int, slot_end_minutes: int}
     */
    private function resolveCalendarSlotBounds(
        LocationAvailability $availability,
        int $locationId,
        array $dateKeys,
        \Illuminate\Support\Collection $bookingModels,
        string $timezone
    ): array {
        $startMinutes = null;
        $endMinutes = null;

        foreach ($dateKeys as $dayKey) {
            try {
                $date = CarbonImmutable::createFromFormat('Y-m-d', $dayKey, $timezone)->startOfDay();
            } catch (Throwable) {
                continue;
            }

            $intervals = $availability->intervalsForDate($locationId, $date);

            foreach ($intervals as $interval) {
                $opensAt = $this->minuteOfDayFromTime((string) ($interval['opens_at'] ?? '00:00'));
                $closesAt = $this->minuteOfDayFromTime((string) ($interval['closes_at'] ?? '00:00'));

                if ($closesAt <= $opensAt) {
                    continue;
                }

                $startMinutes = $startMinutes === null ? $opensAt : min($startMinutes, $opensAt);
                $endMinutes = $endMinutes === null ? $closesAt : max($endMinutes, $closesAt);
            }
        }

        if ($startMinutes === null || $endMinutes === null) {
            $baseOpeningBounds = $this->resolveBaseOpeningBounds($locationId);

            if (is_array($baseOpeningBounds)) {
                $startMinutes = $baseOpeningBounds['slot_start_minutes'];
                $endMinutes = $baseOpeningBounds['slot_end_minutes'];
            }
        }

        if ($startMinutes === null || $endMinutes === null) {
            $bookingBounds = $this->resolveBoundsFromBookings($bookingModels);

            if (is_array($bookingBounds)) {
                $startMinutes = $bookingBounds['slot_start_minutes'];
                $endMinutes = $bookingBounds['slot_end_minutes'];
            }
        }

        if ($startMinutes === null || $endMinutes === null) {
            $startMinutes = 0;
            $endMinutes = 24 * 60;
        }

        $normalizedStart = intdiv(max(0, min($startMinutes, (24 * 60) - 1)), 15) * 15;
        $normalizedEnd = intdiv(max($normalizedStart + 15, min($endMinutes, 24 * 60)) + 14, 15) * 15;
        $normalizedEnd = max($normalizedStart + 15, min($normalizedEnd, 24 * 60));

        return [
            'slot_start_minutes' => $normalizedStart,
            'slot_end_minutes' => $normalizedEnd,
        ];
    }

    /**
     * @return array{slot_start_minutes: int, slot_end_minutes: int}|null
     */
    private function resolveBaseOpeningBounds(int $locationId): ?array
    {
        $startMinutes = null;
        $endMinutes = null;

        $openingHours = LocationOpeningHour::query()
            ->where('location_id', $locationId)
            ->get(['opens_at', 'closes_at']);

        foreach ($openingHours as $openingHour) {
            $opensAt = $this->minuteOfDayFromTime((string) $openingHour->opens_at);
            $closesAt = $this->minuteOfDayFromTime((string) $openingHour->closes_at);

            if ($closesAt <= $opensAt) {
                continue;
            }

            $startMinutes = $startMinutes === null ? $opensAt : min($startMinutes, $opensAt);
            $endMinutes = $endMinutes === null ? $closesAt : max($endMinutes, $closesAt);
        }

        if ($startMinutes === null || $endMinutes === null) {
            return null;
        }

        return [
            'slot_start_minutes' => $startMinutes,
            'slot_end_minutes' => $endMinutes,
        ];
    }

    /**
     * @param \Illuminate\Support\Collection<int, Booking> $bookingModels
     * @return array{slot_start_minutes: int, slot_end_minutes: int}|null
     */
    private function resolveBoundsFromBookings(\Illuminate\Support\Collection $bookingModels): ?array
    {
        $startMinutes = null;
        $endMinutes = null;

        foreach ($bookingModels as $booking) {
            $bookingStartMinutes = ((int) $booking->starts_at->format('H') * 60) + (int) $booking->starts_at->format('i');
            $bookingEndMinutes = ((int) $booking->ends_at->format('H') * 60) + (int) $booking->ends_at->format('i');

            if ($bookingEndMinutes <= $bookingStartMinutes) {
                continue;
            }

            $startMinutes = $startMinutes === null ? $bookingStartMinutes : min($startMinutes, $bookingStartMinutes);
            $endMinutes = $endMinutes === null ? $bookingEndMinutes : max($endMinutes, $bookingEndMinutes);
        }

        if ($startMinutes === null || $endMinutes === null) {
            return null;
        }

        return [
            'slot_start_minutes' => $startMinutes,
            'slot_end_minutes' => $endMinutes,
        ];
    }

    /**
     * @return list<string>
     */
    private function buildTimeSlots(int $slotStartMinutes, int $slotEndMinutes): array
    {
        $start = max(0, min($slotStartMinutes, (24 * 60) - 15));
        $end = max($start + 15, min($slotEndMinutes, 24 * 60));
        $lastSlotStart = $end - 15;
        $timeSlots = [];

        for ($minutes = $start; $minutes <= $lastSlotStart; $minutes += 15) {
            $timeSlots[] = sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
        }

        return $timeSlots;
    }

    private function minuteOfDayFromTime(string $time): int
    {
        $parts = explode(':', $time);
        $hours = (int) ($parts[0] ?? 0);
        $minutes = (int) ($parts[1] ?? 0);
        $total = ($hours * 60) + $minutes;

        return max(0, min($total, 24 * 60));
    }

    private function selectedBookingPayload(Booking $booking): array
    {
        $history = [
            [
                'label' => 'Oprettet',
                'value' => $booking->created_at?->format('d.m.Y H:i') ?? '-',
            ],
        ];

        if ($booking->completed_at) {
            $history[] = [
                'label' => 'Gennemført',
                'value' => trim($booking->completed_at->format('d.m.Y H:i') . ' · ' . ($booking->completedBy?->name ?? 'Auto')),
            ];
        }

        if ($booking->isCanceled()) {
            $history[] = [
                'label' => 'Annulleret',
                'value' => $booking->updated_at?->format('d.m.Y H:i') ?? '-',
            ];
        }

        return [
            'id' => $booking->id,
            'status' => $booking->status,
            'status_label' => $this->statusLabel($booking->status),
            'customer_name' => $booking->customer?->name ?? 'Ukendt kunde',
            'customer_email' => $booking->customer?->email,
            'customer_phone' => $booking->customer?->phone,
            'service_id' => (int) ($booking->service_id ?? 0),
            'service_name' => $booking->service?->name ?? 'Booking',
            'service_duration' => max(15, (int) $booking->starts_at->diffInMinutes($booking->ends_at)),
            'location_name' => $booking->location?->name ?? 'Ukendt lokation',
            'staff_user_id' => (int) ($booking->staff_user_id ?? 0),
            'staff_name' => $booking->staffMember?->name ?? 'Ukendt medarbejder',
            'starts_at_human' => $booking->starts_at->format('d.m.Y H:i'),
            'ends_at_human' => $booking->ends_at->format('d.m.Y H:i'),
            'booking_date_input' => (string) old('booking_date', $booking->starts_at->format('Y-m-d')),
            'booking_time_input' => (string) old('booking_time', $booking->starts_at->format('H:i')),
            'staff_user_id_input' => (string) old('staff_user_id', (string) $booking->staff_user_id),
            'notes' => $booking->notes,
            'can_edit' => $booking->isConfirmed(),
            'can_complete' => $booking->isConfirmed(),
            'can_cancel' => $booking->isConfirmed() || $booking->isCompleted(),
            'history' => $history,
        ];
    }

    private function autoCompleteExpiredBookings(int $tenantId, int $locationId, string $timezone): void
    {
        $now = CarbonImmutable::now($timezone);

        Booking::query()
            ->where('tenant_id', $tenantId)
            ->where('location_id', $locationId)
            ->where('status', Booking::STATUS_CONFIRMED)
            ->where('ends_at', '<=', $now)
            ->update([
                'status' => Booking::STATUS_COMPLETED,
                'completed_at' => $now,
                'completed_by_user_id' => null,
            ]);
    }

    private function normalizeHexColor(?string $color): string
    {
        if (! is_string($color) || ! preg_match('/^#([A-Fa-f0-9]{6})$/', $color)) {
            return '#5C80BC';
        }

        return strtoupper($color);
    }

    private function resolveCalendarTimezone(?string $locationTimezone, ?string $tenantTimezone): string
    {
        $candidates = [
            $locationTimezone,
            $tenantTimezone,
            is_string(config('app.timezone')) ? config('app.timezone') : null,
            'UTC',
        ];

        foreach ($candidates as $candidate) {
            if (! is_string($candidate) || trim($candidate) === '') {
                continue;
            }

            try {
                new \DateTimeZone($candidate);

                return $candidate;
            } catch (Throwable) {
                continue;
            }
        }

        return 'UTC';
    }

    private function hexToRgba(string $hex, float $alpha): string
    {
        $clean = ltrim($hex, '#');
        $red = hexdec(substr($clean, 0, 2));
        $green = hexdec(substr($clean, 2, 2));
        $blue = hexdec(substr($clean, 4, 2));

        return "rgba({$red}, {$green}, {$blue}, {$alpha})";
    }

    private function isWorkShiftsEnabledForTenant(int $tenantId): bool
    {
        return (bool) (Tenant::query()
            ->whereKey($tenantId)
            ->value('work_shifts_enabled') ?? true);
    }

}
