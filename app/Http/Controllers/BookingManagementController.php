<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Support\ActivityLogger;
use App\Support\BookingSlotManager;
use App\Support\BookingSmsNotifier;
use App\Support\LocationAvailability;
use App\Support\WorkShiftAvailability;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BookingManagementController extends Controller
{
    public function store(
        Request $request,
        LocationAvailability $availability,
        BookingSlotManager $slotManager,
        WorkShiftAvailability $shiftAvailability,
        BookingSmsNotifier $smsNotifier,
        ?ActivityLogger $activityLogger = null
    ): RedirectResponse
    {
        $activityLogger ??= app(ActivityLogger::class);
        $tenantId = $this->resolveTenantId($request);
        abort_if($tenantId <= 0, 500, 'Ingen aktiv tenant er konfigureret.');
        $workShiftsEnabled = $this->isWorkShiftsEnabledForTenant($tenantId);
        /** @var User $actor */
        $actor = $request->user();

        $validated = $request->validate([
            'create_location_id' => [
                'required',
                'integer',
                Rule::exists('locations', 'id')->where(
                    fn ($query) => $query
                        ->where('tenant_id', $tenantId)
                        ->where('is_active', true)
                ),
            ],
            'create_service_id' => [
                'required',
                'integer',
                Rule::exists('services', 'id')->where(
                    fn ($query) => $query->where('tenant_id', $tenantId)
                ),
            ],
            'create_staff_user_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where(
                    fn ($query) => $query
                        ->where('tenant_id', $tenantId)
                        ->where('is_bookable', true)
                ),
            ],
            'create_booking_date' => ['required', 'date'],
            'create_booking_time' => ['required', 'date_format:H:i'],
            'create_customer_name' => ['required', 'string', 'max:255'],
            'create_customer_email' => ['nullable', 'email', 'max:255'],
            'create_customer_phone' => ['nullable', 'string', 'max:50'],
            'create_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $locationId = (int) $validated['create_location_id'];

        if (! $this->canAccessLocation($request, $tenantId, $locationId)) {
            throw ValidationException::withMessages([
                'create_location_id' => 'Du har ikke adgang til den valgte lokation.',
            ]);
        }

        $location = Location::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->find($locationId);

        if (! $location) {
            throw ValidationException::withMessages([
                'create_location_id' => 'Lokationen kunne ikke findes.',
            ]);
        }

        $service = Service::queryForLocation($tenantId, $locationId)
            ->where('location_settings.is_active', true)
            ->whereKey((int) $validated['create_service_id'])
            ->first();

        if (! $service) {
            throw ValidationException::withMessages([
                'create_service_id' => 'Den valgte ydelse er ikke tilgængelig på den valgte lokation.',
            ]);
        }

        $durationMinutes = $service->effectiveDurationMinutes();
        $bufferBeforeMinutes = $service->bufferBeforeMinutes();
        $bufferAfterMinutes = $service->bufferAfterMinutes();

        if ($durationMinutes < 15) {
            throw ValidationException::withMessages([
                'create_service_id' => 'Ydelsen mangler en gyldig varighed.',
            ]);
        }

        $staffMember = User::query()
            ->where('tenant_id', $tenantId)
            ->bookable()
            ->whereKey((int) $validated['create_staff_user_id'])
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
            ->first();

        if (! $staffMember) {
            throw ValidationException::withMessages([
                'create_staff_user_id' => 'Den valgte medarbejder er ikke tilgængelig på den valgte lokation.',
            ]);
        }

        $locationTimezone = $this->resolveTimezone($location->timezone);
        $startsAt = CarbonImmutable::createFromFormat(
            'Y-m-d H:i',
            $validated['create_booking_date'] . ' ' . $validated['create_booking_time'],
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
                'create_booking_time' => 'Vælg et tidspunkt på et kvarter, fx 09:00, 09:15 eller 09:30.',
            ]);
        }

        if (! $availability->allowsInterval(
            (int) $location->id,
            $blockedInterval['starts_at'],
            $blockedInterval['ends_at']
        )) {
            throw ValidationException::withMessages([
                'create_booking_time' => 'Tiden ligger uden for åbningstid/undtagelser for lokationen.',
            ]);
        }

        if (
            $workShiftsEnabled &&
            ! $shiftAvailability->userCoversIntervalForDate(
                $tenantId,
                (int) $location->id,
                (int) $staffMember->id,
                $blockedInterval['starts_at'],
                $blockedInterval['ends_at']
            )
        ) {
            throw ValidationException::withMessages([
                'create_booking_time' => 'Medarbejderen er ikke på service-vagt i det valgte tidsrum (inkl. pause).',
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
                'create_booking_time' => 'Det valgte tidspunkt er ikke ledigt hos den medarbejder.',
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
                'notes' => filled($validated['create_notes'] ?? null) ? trim((string) $validated['create_notes']) : null,
            ]);

            $this->syncBookingSlots($slotManager, $booking, 'create_booking_time');

            return $booking;
        });

        try {
            $smsNotifier->sendConfirmation($booking);
        } catch (\Throwable $exception) {
            report($exception);
        }

        $activityLogger->logBookingCreated($actor, $booking->fresh());

        return redirect()
            ->route('booking-calender', $this->calendarQueryFromRequest($request, [
                'location_id' => $locationId,
                'selected_booking' => (int) $booking->id,
            ]))
            ->with('status', 'Bookingen er oprettet.');
    }

    public function update(
        Request $request,
        Booking $booking,
        LocationAvailability $availability,
        BookingSlotManager $slotManager,
        WorkShiftAvailability $shiftAvailability,
        ?ActivityLogger $activityLogger = null
    ): RedirectResponse
    {
        $activityLogger ??= app(ActivityLogger::class);
        $tenantId = $this->resolveTenantId($request);
        abort_if($tenantId <= 0, 500, 'Ingen aktiv tenant er konfigureret.');
        $workShiftsEnabled = $this->isWorkShiftsEnabledForTenant($tenantId);
        abort_if((int) $booking->tenant_id !== $tenantId, 404);
        abort_if(! $this->canAccessLocation($request, $tenantId, (int) $booking->location_id), 404);
        /** @var User $actor */
        $actor = $request->user();

        if (! $booking->isConfirmed()) {
            return redirect()
                ->back()
                ->with('status', 'Kun bekræftede bookinger kan redigeres.');
        }

        $booking->loadMissing('location:id,timezone');
        $beforeSnapshot = $activityLogger->bookingSnapshot($booking);
        $durationMinutes = max(15, (int) $booking->starts_at->diffInMinutes($booking->ends_at));
        $bufferBeforeMinutes = max(0, (int) ($booking->buffer_before_minutes ?? 0));
        $bufferAfterMinutes = max(0, (int) ($booking->buffer_after_minutes ?? 0));

        $locationTimezone = $this->resolveTimezone($booking->location?->timezone);

        $validated = $request->validate([
            'booking_date' => ['required', 'date'],
            'booking_time' => ['required', 'date_format:H:i'],
            'staff_user_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where(
                    fn ($query) => $query
                        ->where('tenant_id', $tenantId)
                        ->where('is_bookable', true)
                        ->whereExists(function ($subQuery) use ($booking): void {
                            $subQuery->selectRaw('1')
                                ->from('location_user')
                                ->whereColumn('location_user.user_id', 'users.id')
                                ->where('location_user.location_id', $booking->location_id)
                                ->where('location_user.is_active', true);
                        })
                        ->where(function ($competencyQuery) use ($booking): void {
                            $serviceId = (int) $booking->service_id;
                            $locationId = (int) $booking->location_id;

                            $competencyQuery
                                ->where(function ($globalQuery) use ($serviceId): void {
                                    $globalQuery
                                        ->where('users.competency_scope', User::COMPETENCY_SCOPE_GLOBAL)
                                        ->whereExists(function ($serviceQuery) use ($serviceId): void {
                                            $serviceQuery
                                                ->selectRaw('1')
                                                ->from('service_user')
                                                ->whereColumn('service_user.user_id', 'users.id')
                                                ->where('service_user.service_id', $serviceId);
                                        });
                                })
                                ->orWhere(function ($locationQuery) use ($serviceId, $locationId): void {
                                    $locationQuery
                                        ->where('users.competency_scope', User::COMPETENCY_SCOPE_LOCATION)
                                        ->whereExists(function ($serviceQuery) use ($serviceId, $locationId): void {
                                            $serviceQuery
                                                ->selectRaw('1')
                                                ->from('location_service_user')
                                                ->whereColumn('location_service_user.user_id', 'users.id')
                                                ->where('location_service_user.location_id', $locationId)
                                                ->where('location_service_user.service_id', $serviceId);
                                        });
                                });
                        })
                ),
            ],
        ]);

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

        if (! $availability->allowsInterval(
            (int) $booking->location_id,
            $blockedInterval['starts_at'],
            $blockedInterval['ends_at']
        )) {
            throw ValidationException::withMessages([
                'booking_time' => 'Tiden ligger uden for åbningstid/undtagelser for bookingens lokation.',
            ]);
        }

        if (
            $workShiftsEnabled &&
            ! $shiftAvailability->userCoversIntervalForDate(
                $tenantId,
                (int) $booking->location_id,
                (int) $validated['staff_user_id'],
                $blockedInterval['starts_at'],
                $blockedInterval['ends_at']
            )
        ) {
            throw ValidationException::withMessages([
                'booking_time' => 'Medarbejderen er ikke på service-vagt i det valgte tidsrum (inkl. pause).',
            ]);
        }

        if ($this->hasOverlap(
            $slotManager,
            $tenantId,
            (int) $validated['staff_user_id'],
            $blockedInterval['starts_at'],
            $blockedInterval['ends_at'],
            $booking->id
        )) {
            throw ValidationException::withMessages([
                'booking_time' => 'Det valgte tidspunkt er ikke ledigt hos den medarbejder. Prøv et andet tidspunkt.',
            ]);
        }

        DB::transaction(function () use ($booking, $validated, $startsAt, $endsAt, $slotManager): void {
            $booking->update([
                'staff_user_id' => (int) $validated['staff_user_id'],
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
            ]);

            $this->syncBookingSlots($slotManager, $booking->refresh(), 'booking_time');
        });

        $activityLogger->logBookingUpdated($actor, $booking->fresh(), $beforeSnapshot);

        return redirect()
            ->back()
            ->with('status', 'Bookingen er opdateret.');
    }

    public function cancel(
        Request $request,
        Booking $booking,
        BookingSlotManager $slotManager,
        ?ActivityLogger $activityLogger = null
    ): RedirectResponse
    {
        $activityLogger ??= app(ActivityLogger::class);
        $tenantId = $this->resolveTenantId($request);
        abort_if($tenantId <= 0, 500, 'Ingen aktiv tenant er konfigureret.');
        abort_if((int) $booking->tenant_id !== $tenantId, 404);
        abort_if(! $this->canAccessLocation($request, $tenantId, (int) $booking->location_id), 404);
        /** @var User $actor */
        $actor = $request->user();

        if ($booking->isCanceled()) {
            $slotManager->clearSlotsForBooking((int) $booking->id);

            return redirect()
                ->back()
                ->with('status', 'Bookingen var allerede annulleret.');
        }

        DB::transaction(function () use ($booking, $slotManager): void {
            $booking->update([
                'status' => Booking::STATUS_CANCELED,
                'completed_at' => null,
                'completed_by_user_id' => null,
            ]);

            $slotManager->clearSlotsForBooking((int) $booking->id);
        });

        $activityLogger->logBookingCanceled($actor, $booking->fresh());

        return redirect()
            ->back()
            ->with('status', 'Bookingen er nu annulleret.');
    }

    public function complete(
        Request $request,
        Booking $booking,
        ?ActivityLogger $activityLogger = null
    ): RedirectResponse
    {
        $activityLogger ??= app(ActivityLogger::class);
        $tenantId = $this->resolveTenantId($request);
        abort_if($tenantId <= 0, 500, 'Ingen aktiv tenant er konfigureret.');
        abort_if((int) $booking->tenant_id !== $tenantId, 404);
        abort_if(! $this->canAccessLocation($request, $tenantId, (int) $booking->location_id), 404);
        /** @var User $actor */
        $actor = $request->user();

        if ($booking->isCompleted()) {
            return redirect()
                ->back()
                ->with('status', 'Bookingen var allerede markeret som gennemført.');
        }

        if ($booking->isCanceled()) {
            return redirect()
                ->back()
                ->with('status', 'En annulleret booking kan ikke markeres som gennemført.');
        }

        if (! $booking->isConfirmed()) {
            return redirect()
                ->back()
                ->with('status', 'Bookingen er i en ugyldig status for gennemføring.');
        }

        $booking->update([
            'status' => Booking::STATUS_COMPLETED,
            'completed_at' => now(),
            'completed_by_user_id' => $request->user()?->id,
        ]);

        $activityLogger->logBookingCompleted($actor, $booking->fresh());

        return redirect()
            ->back()
            ->with('status', 'Bookingen er markeret som gennemført.');
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
        CarbonImmutable $endsAt,
        ?int $ignoreBookingId = null
    ): bool {
        return $slotManager->hasConflict(
            $tenantId,
            $staffUserId,
            $startsAt,
            $endsAt,
            $ignoreBookingId
        );
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
        $name = trim((string) $validated['create_customer_name']);
        $email = filled($validated['create_customer_email'] ?? null)
            ? strtolower(trim((string) $validated['create_customer_email']))
            : null;
        $phone = filled($validated['create_customer_phone'] ?? null)
            ? trim((string) $validated['create_customer_phone'])
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

    private function calendarQueryFromRequest(Request $request, array $overrides = []): array
    {
        $base = collect($request->only([
            'date',
            'week',
            'location_id',
            'status',
            'staff_user_id',
            'service_id',
            'selected_booking',
        ]))
            ->filter(static fn (mixed $value): bool => ! ($value === null || $value === ''))
            ->all();

        foreach ($overrides as $key => $value) {
            if ($value === null || $value === '') {
                unset($base[$key]);
                continue;
            }

            $base[$key] = $value;
        }

        return $base;
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
}
