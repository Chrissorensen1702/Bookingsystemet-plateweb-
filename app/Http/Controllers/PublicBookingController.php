<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Location;
use App\Models\LocationOpeningHour;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Support\BookingSlotManager;
use App\Support\BookingSmsNotifier;
use App\Support\LocationAvailability;
use App\Support\UploadsStorage;
use App\Support\WorkShiftAvailability;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class PublicBookingController extends Controller
{
    private const SLOT_MINUTES = 15;
    private const WEEK_DAYS = [
        1 => 'Mandag',
        2 => 'Tirsdag',
        3 => 'Onsdag',
        4 => 'Torsdag',
        5 => 'Fredag',
        6 => 'Lørdag',
        7 => 'Søndag',
    ];
    public function create(
        Request $request,
        LocationAvailability $availability,
        BookingSlotManager $slotManager,
        WorkShiftAvailability $shiftAvailability
    ): View
    {
        $tenantId = $this->resolveTenantId($request);
        abort_if($tenantId <= 0, 500, 'Ingen aktiv tenant er konfigureret.');
        $tenant = Tenant::query()
            ->with(['plan:id,code,name,requires_powered_by'])
            ->select([
                'id',
                'name',
                'slug',
                'plan_id',
                'public_brand_name',
                'public_logo_path',
                'public_logo_alt',
                'public_primary_color',
                'public_accent_color',
                'show_powered_by',
                'require_service_categories',
                'work_shifts_enabled',
            ])
            ->findOrFail($tenantId);
        $requireServiceCategories = (bool) ($tenant->require_service_categories ?? true);
        $workShiftsEnabled = (bool) ($tenant->work_shifts_enabled ?? true);
        $selectedLocationId = $this->resolveLocationId($request, $tenantId);
        abort_if($selectedLocationId <= 0, 500, 'Ingen aktiv lokation er konfigureret.');
        $tenantQuery = trim((string) $request->query('tenant', ''));
        $locations = Location::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get([
                'id',
                'name',
                'slug',
                'timezone',
                'address_line_1',
                'address_line_2',
                'postal_code',
                'city',
                'public_booking_intro_text',
                'public_contact_phone',
                'public_contact_email',
            ]);
        $selectedLocation = $locations->firstWhere('id', $selectedLocationId);
        $bookingIntroText = trim((string) ($selectedLocation?->public_booking_intro_text ?? ''));
        $openingHoursByDay = LocationOpeningHour::query()
            ->where('location_id', $selectedLocationId)
            ->orderBy('weekday')
            ->orderBy('opens_at')
            ->get()
            ->groupBy('weekday');
        $locationTimezone = $this->resolveTimezone($selectedLocation?->timezone);
        $selectedDate = $this->resolveBookingDate(
            (string) old('booking_date', $request->query('booking_date', '')),
            $locationTimezone
        );
        $publicBrand = $this->resolvePublicBrand($tenant);
        $services = Service::queryForLocation($tenantId, $selectedLocationId)
            ->where('location_settings.is_active', true)
            ->where('services.is_online_bookable', true)
            ->orderByRaw('COALESCE(location_settings.sort_order, services.sort_order)')
            ->orderBy('services.name')
            ->orderBy('service_categories.name')
            ->get();
        $staffMembers = User::query()
            ->where('tenant_id', $tenantId)
            ->whereHas('locations', function (Builder $query) use ($selectedLocationId): void {
                $query->whereKey($selectedLocationId)
                    ->where('location_user.is_active', true);
            })
            ->bookable()
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'initials', 'competency_scope']);
        $serviceIdsForLocation = $services
            ->pluck('id')
            ->map(static fn (int $id): int => $id)
            ->all();
        $staffServiceMap = [];

        if ($staffMembers->isNotEmpty() && $serviceIdsForLocation !== []) {
            $globalUserIds = $staffMembers
                ->filter(static fn (User $user): bool => ! $user->usesLocationCompetencies())
                ->pluck('id')
                ->map(static fn (int $id): int => $id)
                ->all();
            $locationScopedUserIds = $staffMembers
                ->filter(static fn (User $user): bool => $user->usesLocationCompetencies())
                ->pluck('id')
                ->map(static fn (int $id): int => $id)
                ->all();

            if ($globalUserIds !== []) {
                $globalMap = DB::table('service_user')
                    ->select(['user_id', 'service_id'])
                    ->whereIn('user_id', $globalUserIds)
                    ->whereIn('service_id', $serviceIdsForLocation)
                    ->get()
                    ->groupBy('user_id')
                    ->map(static function ($rows): array {
                        return $rows
                            ->pluck('service_id')
                            ->map(static fn (int $id): int => $id)
                            ->unique()
                            ->values()
                            ->all();
                    })
                    ->all();

                $staffServiceMap = array_merge($staffServiceMap, $globalMap);
            }

            if ($locationScopedUserIds !== []) {
                $localMap = DB::table('location_service_user')
                    ->select(['user_id', 'service_id'])
                    ->where('location_id', $selectedLocationId)
                    ->whereIn('user_id', $locationScopedUserIds)
                    ->whereIn('service_id', $serviceIdsForLocation)
                    ->get()
                    ->groupBy('user_id')
                    ->map(static function ($rows): array {
                        return $rows
                            ->pluck('service_id')
                            ->map(static fn (int $id): int => $id)
                            ->unique()
                            ->values()
                            ->all();
                    })
                    ->all();

                $staffServiceMap = array_merge($staffServiceMap, $localMap);
            }
        }

        $staffMembers->each(function (User $staffMember) use ($staffServiceMap): void {
            $staffMember->setAttribute(
                'eligible_service_ids',
                $staffServiceMap[(int) $staffMember->id] ?? []
            );
        });

        $selectedServiceId = max(0, (int) old('service_id', 0));
        $selectedStaffUserId = max(0, (int) old('staff_user_id', 0));

        if ($selectedServiceId > 0) {
            $staffMembers = $staffMembers
                ->filter(static function (User $staffMember) use ($selectedServiceId): bool {
                    $eligibleServiceIds = collect($staffMember->getAttribute('eligible_service_ids'))
                        ->map(static fn (int $id): int => $id)
                        ->all();

                    return in_array($selectedServiceId, $eligibleServiceIds, true);
                })
                ->values();
        }

        if ($workShiftsEnabled && $staffMembers->isNotEmpty()) {
            $staffCoverageByUser = $shiftAvailability->coverageByUserForDate(
                $tenantId,
                $selectedLocationId,
                $selectedDate,
                $staffMembers
                    ->pluck('id')
                    ->map(static fn (int $id): int => $id)
                    ->all(),
                true
            );

            $staffMembers = $staffMembers
                ->filter(static fn (User $staffMember): bool => $shiftAvailability->userHasAnyCoverage(
                    $staffCoverageByUser,
                    (int) $staffMember->id
                ))
                ->values();
        }

        $selectedService = $selectedServiceId > 0
            ? $services->firstWhere('id', $selectedServiceId)
            : null;
        $selectedServiceRequiresStaffSelection = $workShiftsEnabled
            && ($selectedService?->requiresStaffSelection() ?? true);
        $serviceDurationMinutes = $selectedService?->effectiveDurationMinutes() ?? 15;
        $serviceBufferBeforeMinutes = $selectedService?->bufferBeforeMinutes() ?? 0;
        $serviceBufferAfterMinutes = $selectedService?->bufferAfterMinutes() ?? 0;
        $serviceMinNoticeMinutes = $selectedService?->minNoticeMinutes() ?? 0;
        $serviceMaxAdvanceDays = $selectedService?->maxAdvanceDays();
        $eligibleStaffIds = $selectedServiceRequiresStaffSelection && $selectedStaffUserId > 0
            ? ($staffMembers->contains('id', $selectedStaffUserId) ? [$selectedStaffUserId] : [])
            : $staffMembers
                ->pluck('id')
                ->map(static fn (int $id): int => $id)
                ->all();
        $candidateTimeOptions = $availability->startTimesForDate(
            $selectedLocationId,
            $selectedDate,
            self::SLOT_MINUTES,
            $serviceDurationMinutes,
            $serviceBufferBeforeMinutes,
            $serviceBufferAfterMinutes
        );
        $availableStartTimes = $this->resolveAvailableStartTimes(
            $slotManager,
            $shiftAvailability,
            $tenantId,
            $selectedLocationId,
            $selectedDate,
            $eligibleStaffIds,
            $candidateTimeOptions,
            $serviceDurationMinutes,
            $serviceBufferBeforeMinutes,
            $serviceBufferAfterMinutes,
            $workShiftsEnabled,
            true
        );
        $timeOptions = $this->filterTimeOptionsWithinBookingWindow(
            $availableStartTimes,
            $selectedDate,
            $locationTimezone,
            $serviceMinNoticeMinutes,
            $serviceMaxAdvanceDays
        );

        return view('public-booking', [
            'services' => $services,
            'staffMembers' => $staffMembers,
            'locations' => $locations,
            'weekDays' => self::WEEK_DAYS,
            'openingHoursByDay' => $openingHoursByDay,
            'selectedLocation' => $selectedLocation,
            'selectedLocationId' => $selectedLocationId,
            'bookingIntroText' => $bookingIntroText !== ''
                ? $bookingIntroText
                : 'Vælg ydelse, tidspunkt og kontaktoplysninger. Når du opretter, ligger bookingen straks i kalenderen.',
            'timeOptions' => $timeOptions,
            'timeOptionsDate' => $selectedDate->toDateString(),
            'bookingWindowLabel' => $selectedService
                ? $this->bookingWindowLabelForService($selectedService)
                : 'Ingen bookingbegrænsning valgt endnu',
            'selectedServiceRequiresStaffSelection' => $selectedServiceRequiresStaffSelection,
            'tenantQuery' => $tenantQuery !== '' ? $tenantQuery : null,
            'publicBrand' => $publicBrand,
            'requiresServiceCategories' => $requireServiceCategories,
            'workShiftsEnabled' => $workShiftsEnabled,
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
        $workShiftsEnabled = (bool) (Tenant::query()
            ->whereKey($tenantId)
            ->value('work_shifts_enabled') ?? true);

        $validated = $request->validate([
            'location_id' => [
                'required',
                'integer',
                Rule::exists('locations', 'id')->where(
                    fn ($query) => $query->where('tenant_id', $tenantId)->where('is_active', true)
                ),
            ],
            'booking_date' => ['required', 'date', 'after_or_equal:today'],
            'service_id' => [
                'nullable',
                'integer',
                Rule::exists('services', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)),
            ],
            'staff_user_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where(
                    fn ($query) => $query
                        ->where('tenant_id', $tenantId)
                        ->where('is_bookable', true)
                ),
            ],
        ]);

        $location = Location::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->findOrFail((int) $validated['location_id']);
        $locationTimezone = $this->resolveTimezone($location->timezone);
        $date = $this->resolveBookingDate((string) $validated['booking_date'], $locationTimezone);
        $serviceId = max(0, (int) ($validated['service_id'] ?? 0));
        $staffUserId = max(0, (int) ($validated['staff_user_id'] ?? 0));
        $serviceDurationMinutes = 15;
        $serviceBufferBeforeMinutes = 0;
        $serviceBufferAfterMinutes = 0;
        $serviceMinNoticeMinutes = 0;
        $serviceMaxAdvanceDays = null;
        $serviceRequiresStaffSelection = $workShiftsEnabled;

        if ($serviceId > 0) {
            $service = Service::queryForLocation($tenantId, (int) $location->id)
                ->where('location_settings.is_active', true)
                ->where('services.is_online_bookable', true)
                ->whereKey($serviceId)
                ->first();

            if (! $service) {
                return response()->json([
                    'time_options' => [],
                    'staff_members' => [],
                    'service_requires_staff_selection' => $serviceRequiresStaffSelection,
                ]);
            }

            $serviceDurationMinutes = $service->effectiveDurationMinutes();
            $serviceBufferBeforeMinutes = $service->bufferBeforeMinutes();
            $serviceBufferAfterMinutes = $service->bufferAfterMinutes();
            $serviceMinNoticeMinutes = $service->minNoticeMinutes();
            $serviceMaxAdvanceDays = $service->maxAdvanceDays();
            $serviceRequiresStaffSelection = $workShiftsEnabled && $service->requiresStaffSelection();
        }

        if (! $serviceRequiresStaffSelection) {
            $staffUserId = 0;
        }

        $staffMembersQuery = User::query()
            ->where('tenant_id', $tenantId)
            ->whereHas('locations', function (Builder $query) use ($location): void {
                $query->whereKey((int) $location->id)
                    ->where('location_user.is_active', true);
            })
            ->bookable();

        if ($serviceId > 0) {
            $staffMembersQuery->where(function (Builder $query) use ($serviceId, $location): void {
                $query->where(function (Builder $scopedQuery) use ($serviceId): void {
                    $scopedQuery
                        ->where('competency_scope', User::COMPETENCY_SCOPE_GLOBAL)
                        ->whereHas('services', function (Builder $serviceQuery) use ($serviceId): void {
                            $serviceQuery->whereKey($serviceId);
                        });
                })->orWhere(function (Builder $scopedQuery) use ($serviceId, $location): void {
                    $scopedQuery
                        ->where('competency_scope', User::COMPETENCY_SCOPE_LOCATION)
                        ->whereExists(function ($existsQuery) use ($serviceId, $location): void {
                            $existsQuery
                                ->select(DB::raw(1))
                                ->from('location_service_user')
                                ->whereColumn('location_service_user.user_id', 'users.id')
                                ->where('location_service_user.location_id', (int) $location->id)
                                ->where('location_service_user.service_id', $serviceId);
                        });
                });
            });
        }

        $staffMembers = $staffMembersQuery
            ->orderBy('name')
            ->get(['id', 'name']);

        if ($workShiftsEnabled && $staffMembers->isNotEmpty()) {
            $staffCoverageByUser = $shiftAvailability->coverageByUserForDate(
                $tenantId,
                (int) $location->id,
                $date,
                $staffMembers
                    ->pluck('id')
                    ->map(static fn (int $id): int => $id)
                    ->all(),
                true
            );

            $staffMembers = $staffMembers
                ->filter(static fn (User $staffMember): bool => $shiftAvailability->userHasAnyCoverage(
                    $staffCoverageByUser,
                    (int) $staffMember->id
                ))
                ->values();
        }

        $staffIdsAtLocation = $staffMembers
            ->pluck('id')
            ->map(static fn (int $id): int => $id)
            ->all();
        $eligibleStaffIds = $serviceRequiresStaffSelection && $staffUserId > 0
            ? (in_array($staffUserId, $staffIdsAtLocation, true) ? [$staffUserId] : [])
            : $staffIdsAtLocation;
        $candidateTimeOptions = $availability->startTimesForDate(
            (int) $location->id,
            $date,
            self::SLOT_MINUTES,
            $serviceDurationMinutes,
            $serviceBufferBeforeMinutes,
            $serviceBufferAfterMinutes
        );
        $availableStartTimes = $this->resolveAvailableStartTimes(
            $slotManager,
            $shiftAvailability,
            $tenantId,
            (int) $location->id,
            $date,
            $eligibleStaffIds,
            $candidateTimeOptions,
            $serviceDurationMinutes,
            $serviceBufferBeforeMinutes,
            $serviceBufferAfterMinutes,
            $workShiftsEnabled,
            true
        );
        $timeOptions = $this->filterTimeOptionsWithinBookingWindow(
            $availableStartTimes,
            $date,
            $locationTimezone,
            $serviceMinNoticeMinutes,
            $serviceMaxAdvanceDays
        );

        return response()->json([
            'time_options' => $timeOptions,
            'service_requires_staff_selection' => $serviceRequiresStaffSelection,
            'staff_members' => $staffMembers
                ->map(static fn (User $staffMember): array => [
                    'id' => (int) $staffMember->id,
                    'name' => (string) $staffMember->name,
                ])
                ->values()
                ->all(),
        ]);
    }

    public function store(
        Request $request,
        LocationAvailability $availability,
        BookingSlotManager $slotManager,
        WorkShiftAvailability $shiftAvailability,
        BookingSmsNotifier $smsNotifier
    ): RedirectResponse
    {
        $tenantId = $this->resolveTenantId($request);
        abort_if($tenantId <= 0, 500, 'Ingen aktiv tenant er konfigureret.');
        $tenantSettings = Tenant::query()
            ->whereKey($tenantId)
            ->first(['require_service_categories', 'work_shifts_enabled']);
        $requireServiceCategories = (bool) ($tenantSettings?->require_service_categories ?? true);
        $workShiftsEnabled = (bool) ($tenantSettings?->work_shifts_enabled ?? true);

        $validated = $request->validate([
            'location_id' => [
                'required',
                'integer',
                Rule::exists('locations', 'id')->where(
                    fn ($query) => $query->where('tenant_id', $tenantId)->where('is_active', true)
                ),
            ],
            'service_category_id' => $requireServiceCategories
                ? [
                    'required',
                    'integer',
                    Rule::exists('service_categories', 'id')->where(
                        fn ($query) => $query->where('tenant_id', $tenantId)->where('is_active', true)
                    ),
                ]
                : [
                    'nullable',
                    'integer',
                    Rule::exists('service_categories', 'id')->where(
                        fn ($query) => $query->where('tenant_id', $tenantId)->where('is_active', true)
                    ),
                ],
            'service_id' => [
                'required',
                'integer',
                Rule::exists('services', 'id')->where(fn ($query) => $query->where('tenant_id', $tenantId)),
            ],
            'staff_user_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where(
                    fn ($query) => $query
                        ->where('tenant_id', $tenantId)
                        ->where('is_bookable', true)
                ),
            ],
            'booking_date' => ['required', 'date', 'after_or_equal:today'],
            'booking_time' => ['required', 'date_format:H:i'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $locationId = (int) $validated['location_id'];

        $location = Location::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->findOrFail($locationId);
        $locationTimezone = $this->resolveTimezone($location->timezone);

        $service = Service::queryForLocation($tenantId, $locationId)
            ->where('location_settings.is_active', true)
            ->where('services.is_online_bookable', true)
            ->whereKey($validated['service_id'])
            ->first();

        if (! $service) {
            throw ValidationException::withMessages([
                'service_id' => 'Den valgte ydelse er ikke tilgængelig pa denne lokation.',
            ]);
        }

        if ($requireServiceCategories) {
            $submittedCategoryId = (int) ($validated['service_category_id'] ?? 0);
            $serviceCategoryId = (int) ($service->service_category_id ?? 0);

            if ($submittedCategoryId <= 0 || $serviceCategoryId <= 0 || $submittedCategoryId !== $serviceCategoryId) {
                throw ValidationException::withMessages([
                    'service_category_id' => 'Vælg en kategori og en ydelse i samme kategori.',
                ]);
            }
        }

        $durationMinutes = $service->effectiveDurationMinutes();
        $bufferBeforeMinutes = $service->bufferBeforeMinutes();
        $bufferAfterMinutes = $service->bufferAfterMinutes();
        $minNoticeMinutes = $service->minNoticeMinutes();
        $maxAdvanceDays = $service->maxAdvanceDays();
        $requiresStaffSelection = $workShiftsEnabled && $service->requiresStaffSelection();
        $requestedStaffUserId = $requiresStaffSelection
            ? max(0, (int) ($validated['staff_user_id'] ?? 0))
            : 0;
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
                'booking_time' => 'Vælg et tidspunkt pa et kvarter, fx 09:00, 09:15 eller 09:30.',
            ]);
        }

        if (! $this->isWithinBookingWindow(
            $startsAt,
            $locationTimezone,
            $minNoticeMinutes,
            $maxAdvanceDays
        )) {
            throw ValidationException::withMessages([
                'booking_time' => 'Tiden ligger uden for booking-vinduet for den valgte ydelse.',
            ]);
        }

        if (! $availability->allowsInterval(
            (int) $location->id,
            $blockedInterval['starts_at'],
            $blockedInterval['ends_at']
        )) {
            throw ValidationException::withMessages([
                'booking_time' => 'Tiden ligger uden for åbningstid/undtagelser for den valgte lokation.',
            ]);
        }

        $eligibleStaffMembers = User::query()
            ->where('tenant_id', $tenantId)
            ->bookable()
            ->whereHas('locations', function (Builder $query) use ($locationId): void {
                $query->whereKey($locationId)
                    ->where('location_user.is_active', true);
            })
            ->where(function (Builder $query) use ($service, $locationId): void {
                $serviceId = (int) $service->id;

                $query->where(function (Builder $scopedQuery) use ($serviceId): void {
                    $scopedQuery
                        ->where('competency_scope', User::COMPETENCY_SCOPE_GLOBAL)
                        ->whereHas('services', function (Builder $serviceQuery) use ($serviceId): void {
                            $serviceQuery->whereKey($serviceId);
                        });
                })->orWhere(function (Builder $scopedQuery) use ($serviceId, $locationId): void {
                    $scopedQuery
                        ->where('competency_scope', User::COMPETENCY_SCOPE_LOCATION)
                        ->whereExists(function ($existsQuery) use ($serviceId, $locationId): void {
                            $existsQuery
                                ->select(DB::raw(1))
                                ->from('location_service_user')
                                ->whereColumn('location_service_user.user_id', 'users.id')
                                ->where('location_service_user.location_id', $locationId)
                                ->where('location_service_user.service_id', $serviceId);
                        });
                });
            })
            ->orderBy('name')
            ->get(['id', 'name']);

        if ($workShiftsEnabled && $eligibleStaffMembers->isNotEmpty()) {
            $shiftCoverageByUser = $shiftAvailability->coverageByUserForDate(
                $tenantId,
                $locationId,
                $startsAt->startOfDay(),
                $eligibleStaffMembers
                    ->pluck('id')
                    ->map(static fn (int $id): int => $id)
                    ->all(),
                true
            );

            $eligibleStaffMembers = $eligibleStaffMembers
                ->filter(static fn (User $staffMember): bool => $shiftAvailability->userCoversInterval(
                    $shiftCoverageByUser,
                    (int) $staffMember->id,
                    $blockedInterval['starts_at'],
                    $blockedInterval['ends_at']
                ))
                ->values();
        }

        if ($eligibleStaffMembers->isEmpty()) {
            throw ValidationException::withMessages([
                'booking_time' => 'Der er ingen behandlere på service-vagt i det valgte tidsrum.',
            ]);
        }

        if ($requiresStaffSelection && $requestedStaffUserId <= 0) {
            throw ValidationException::withMessages([
                'staff_user_id' => 'Vaelg en behandler.',
            ]);
        }

        /** @var User|null $staffMember */
        $staffMember = null;

        if ($requiresStaffSelection) {
            $staffMember = $eligibleStaffMembers
                ->firstWhere('id', $requestedStaffUserId);

            if (! $staffMember) {
                throw ValidationException::withMessages([
                    'staff_user_id' => 'Den valgte medarbejder kan ikke udfore den valgte ydelse pa denne lokation.',
                ]);
            }

            if ($this->hasOverlap(
                $slotManager,
                $tenantId,
                (int) $staffMember->id,
                $blockedInterval['starts_at'],
                $blockedInterval['ends_at']
            )) {
                throw ValidationException::withMessages([
                    'booking_time' => 'Det valgte tidspunkt er ikke ledigt hos den medarbejder. Proev et andet tidspunkt.',
                ]);
            }
        } else {
            foreach ($eligibleStaffMembers as $candidateStaffMember) {
                if (! $candidateStaffMember instanceof User) {
                    continue;
                }

                if (! $this->hasOverlap(
                    $slotManager,
                    $tenantId,
                    (int) $candidateStaffMember->id,
                    $blockedInterval['starts_at'],
                    $blockedInterval['ends_at']
                )) {
                    $staffMember = $candidateStaffMember;
                    break;
                }
            }
        }

        if (! $staffMember) {
            throw ValidationException::withMessages([
                'booking_time' => 'Det valgte tidspunkt er ikke ledigt pa nogen behandler. Proev et andet tidspunkt.',
            ]);
        }

        $customer = $this->resolveCustomer($validated, $tenantId);

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
                'location_id' => $location->id,
                'customer_id' => $customer->id,
                'service_id' => $service->id,
                'staff_user_id' => $staffMember->id,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'buffer_before_minutes' => $bufferBeforeMinutes,
                'buffer_after_minutes' => $bufferAfterMinutes,
                'status' => Booking::STATUS_CONFIRMED,
                'notes' => filled($validated['notes'] ?? null) ? trim($validated['notes']) : null,
            ]);

            $this->syncBookingSlots($slotManager, $booking, 'booking_time');

            return $booking;
        });

        try {
            $smsNotifier->sendConfirmation($booking);
        } catch (Throwable $exception) {
            report($exception);
        }

        $tenantQuery = trim((string) $request->query('tenant', ''));
        $redirectParams = [
            'location_id' => $location->id,
        ];

        if ($tenantQuery !== '') {
            $redirectParams['tenant'] = $tenantQuery;
        }

        return redirect()
            ->route('public-booking.create', $redirectParams)
            ->with('status', 'Din booking er oprettet.')
            ->with('booking_summary', [
                'service' => $service->name,
                'staff_member' => $staffMember->name,
                'date' => $startsAt->format('d.m.Y'),
                'time' => $startsAt->format('H:i'),
            ]);
    }

    private function resolveCustomer(array $validated, int $tenantId): Customer
    {
        $name = trim($validated['name']);
        $email = filled($validated['email'] ?? null) ? strtolower(trim($validated['email'])) : null;
        $phone = filled($validated['phone'] ?? null) ? trim($validated['phone']) : null;

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

    private function startsOnQuarterHour(CarbonImmutable $startsAt): bool
    {
        return in_array((int) $startsAt->format('i'), [0, 15, 30, 45], true);
    }

    private function hasOverlap(
        BookingSlotManager $slotManager,
        int $tenantId,
        int $staffUserId,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt
    ): bool
    {
        return $slotManager->hasConflict($tenantId, $staffUserId, $startsAt, $endsAt);
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

    private function minuteOfDayFromTime(string $time): int
    {
        $parts = explode(':', $time);
        $hours = (int) ($parts[0] ?? 0);
        $minutes = (int) ($parts[1] ?? 0);

        return ($hours * 60) + $minutes;
    }

    /**
     * @param list<string> $timeOptions
     * @return list<string>
     */
    private function filterTimeOptionsWithinBookingWindow(
        array $timeOptions,
        CarbonImmutable $date,
        string $timezone,
        int $minNoticeMinutes,
        ?int $maxAdvanceDays
    ): array {
        return collect($timeOptions)
            ->filter(function (string $time) use ($date, $timezone, $minNoticeMinutes, $maxAdvanceDays): bool {
                try {
                    $startAt = CarbonImmutable::createFromFormat(
                        'Y-m-d H:i',
                        $date->toDateString() . ' ' . $time,
                        $timezone
                    );
                } catch (Throwable) {
                    return false;
                }

                return $this->isWithinBookingWindow($startAt, $timezone, $minNoticeMinutes, $maxAdvanceDays);
            })
            ->values()
            ->all();
    }

    private function isWithinBookingWindow(
        CarbonImmutable $startsAt,
        string $timezone,
        int $minNoticeMinutes,
        ?int $maxAdvanceDays
    ): bool {
        $now = CarbonImmutable::now($timezone);
        $minimumStart = $now->addMinutes(max(0, $minNoticeMinutes));

        if ($startsAt->lt($minimumStart)) {
            return false;
        }

        if ($maxAdvanceDays === null || $maxAdvanceDays <= 0) {
            return true;
        }

        $maximumStart = $now->addDays($maxAdvanceDays)->endOfDay();

        return $startsAt->lte($maximumStart);
    }

    private function bookingWindowLabelForService(Service $service): string
    {
        $parts = [];
        $minNoticeMinutes = $service->minNoticeMinutes();
        $maxAdvanceDays = $service->maxAdvanceDays();

        if ($minNoticeMinutes > 0) {
            $parts[] = 'Min. varsel: ' . $minNoticeMinutes . ' min';
        }

        if ($maxAdvanceDays !== null) {
            $parts[] = 'Kan bookes op til ' . $maxAdvanceDays . ' dage frem';
        }

        if ($parts === []) {
            return 'Ingen bookingbegrænsning';
        }

        return implode(' · ', $parts);
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

    private function resolveTimezone(?string $timezone): string
    {
        $candidate = is_string($timezone) ? trim($timezone) : '';

        return $candidate !== ''
            ? $candidate
            : (string) config('app.timezone', 'UTC');
    }

    private function resolveBookingDate(string $rawDate, string $timezone): CarbonImmutable
    {
        if ($rawDate !== '') {
            try {
                return CarbonImmutable::createFromFormat('Y-m-d', $rawDate, $timezone)->startOfDay();
            } catch (Throwable) {
                // Fall back to "today" in the selected location timezone.
            }
        }

        return CarbonImmutable::now($timezone)->startOfDay();
    }

    /**
     * @return array{
     *   name: string,
     *   logo_url: ?string,
     *   logo_alt: string,
     *   primary_hex: ?string,
     *   primary_rgb: ?string,
     *   accent_hex: ?string,
     *   accent_rgb: ?string,
     *   show_powered_by: bool
     * }
     */
    private function resolvePublicBrand(Tenant $tenant): array
    {
        $brandName = trim((string) $tenant->name);
        $primaryHex = null;
        $accentHex = null;
        $logoUrl = null;
        $logoAlt = $brandName !== '' ? $brandName : 'Booking logo';
        $showPoweredBy = (bool) $tenant->show_powered_by;

        if (filled($tenant->public_brand_name)) {
            $brandName = trim((string) $tenant->public_brand_name);
        }

        $tenantPrimaryHex = $this->normalizeHexColor($tenant->public_primary_color);
        if ($tenantPrimaryHex !== null) {
            $primaryHex = $tenantPrimaryHex;
        }

        $tenantAccentHex = $this->normalizeHexColor($tenant->public_accent_color);
        if ($tenantAccentHex !== null) {
            $accentHex = $tenantAccentHex;
        }

        $tenantLogoUrl = $this->resolveBrandLogoUrl($tenant->public_logo_path, (int) $tenant->id);
        if ($tenantLogoUrl !== null) {
            $logoUrl = $tenantLogoUrl;
        }

        if (filled($tenant->public_logo_alt)) {
            $logoAlt = trim((string) $tenant->public_logo_alt);
        }

        if ((bool) ($tenant->plan?->requires_powered_by ?? false)) {
            $showPoweredBy = true;
        }

        return [
            'name' => $brandName !== '' ? $brandName : 'Booking',
            'logo_url' => $logoUrl,
            'logo_alt' => $logoAlt !== '' ? $logoAlt : 'Booking logo',
            'primary_hex' => $primaryHex,
            'primary_rgb' => $this->hexToRgbTriplet($primaryHex),
            'accent_hex' => $accentHex,
            'accent_rgb' => $this->hexToRgbTriplet($accentHex),
            'show_powered_by' => $showPoweredBy,
        ];
    }

    private function resolveBrandLogoUrl(?string $path, int $tenantId): ?string
    {
        $normalizedPath = UploadsStorage::normalizePath($path);

        if ($normalizedPath === null) {
            return null;
        }

        $expectedStoragePrefix = 'tenant-branding/' . $tenantId . '/branding/';
        $legacyPublicPrefix = 'tenant-assets/' . $tenantId . '/branding/';

        if (
            ! str_starts_with($normalizedPath, $expectedStoragePrefix)
            && ! str_starts_with($normalizedPath, $legacyPublicPrefix)
        ) {
            return null;
        }

        return UploadsStorage::url($normalizedPath);
    }

    private function normalizeHexColor(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = strtoupper(trim($value));

        return preg_match('/^#[A-F0-9]{6}$/', $trimmed) === 1
            ? $trimmed
            : null;
    }

    private function hexToRgbTriplet(?string $hex): ?string
    {
        if (! is_string($hex)) {
            return null;
        }

        $clean = ltrim($hex, '#');

        if (strlen($clean) !== 6 || ! ctype_xdigit($clean)) {
            return null;
        }

        $red = hexdec(substr($clean, 0, 2));
        $green = hexdec(substr($clean, 2, 2));
        $blue = hexdec(substr($clean, 4, 2));

        return "{$red} {$green} {$blue}";
    }
}
