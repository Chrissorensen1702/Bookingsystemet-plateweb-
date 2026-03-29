<?php

namespace App\Support;

use App\Models\Booking;
use App\Models\BookingSlot;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class BookingSlotManager
{
    public function hasConflict(
        int $tenantId,
        int $staffUserId,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        ?int $ignoreBookingId = null
    ): bool {
        $slotStarts = $this->slotStartsForInterval($startsAt, $endsAt);

        if ($slotStarts === []) {
            return false;
        }

        $query = BookingSlot::query()
            ->where('tenant_id', $tenantId)
            ->where('staff_user_id', $staffUserId)
            ->whereIn('slot_start', $slotStarts);

        if ($ignoreBookingId !== null) {
            $query->where('booking_id', '!=', $ignoreBookingId);
        }

        return $query->exists();
    }

    public function syncSlotsForBooking(Booking $booking): void
    {
        $bookingId = (int) $booking->id;

        if (
            $bookingId <= 0 ||
            (int) $booking->tenant_id <= 0 ||
            (int) $booking->location_id <= 0 ||
            (int) $booking->staff_user_id <= 0 ||
            $booking->status === Booking::STATUS_CANCELED
        ) {
            $this->clearSlotsForBooking($bookingId);

            return;
        }

        $startsAt = CarbonImmutable::instance($booking->starts_at);
        $endsAt = CarbonImmutable::instance($booking->ends_at);
        $bufferBeforeMinutes = max(0, (int) ($booking->buffer_before_minutes ?? 0));
        $bufferAfterMinutes = max(0, (int) ($booking->buffer_after_minutes ?? 0));
        $slotStarts = $this->slotStartsForInterval(
            $startsAt->subMinutes($bufferBeforeMinutes),
            $endsAt->addMinutes($bufferAfterMinutes)
        );

        $this->clearSlotsForBooking($bookingId);

        if ($slotStarts === []) {
            return;
        }

        $now = now();
        $rows = collect($slotStarts)
            ->map(fn (string $slotStart): array => [
                'tenant_id' => (int) $booking->tenant_id,
                'location_id' => (int) $booking->location_id,
                'booking_id' => $bookingId,
                'staff_user_id' => (int) $booking->staff_user_id,
                'slot_start' => $slotStart,
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->all();

        BookingSlot::query()->insert($rows);
    }

    public function clearSlotsForBooking(int $bookingId): void
    {
        if ($bookingId <= 0) {
            return;
        }

        BookingSlot::query()
            ->where('booking_id', $bookingId)
            ->delete();
    }

    /**
     * @param list<int> $staffUserIds
     * @return array<string, array<string, int>>
     */
    public function occupiedStaffCountsByDayAndTime(
        int $tenantId,
        array $staffUserIds,
        CarbonImmutable $rangeStart,
        CarbonImmutable $rangeEnd
    ): array {
        $staffUserIds = collect($staffUserIds)
            ->map(static fn (int $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($tenantId <= 0 || $staffUserIds === []) {
            return [];
        }

        /** @var Collection<int, object{slot_start: string, occupied_staff_count: int|string}> $rows */
        $rows = BookingSlot::query()
            ->selectRaw('slot_start, COUNT(DISTINCT staff_user_id) as occupied_staff_count')
            ->where('tenant_id', $tenantId)
            ->whereIn('staff_user_id', $staffUserIds)
            ->whereBetween('slot_start', [$rangeStart, $rangeEnd])
            ->groupBy('slot_start')
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $slot = CarbonImmutable::parse($row->slot_start, (string) config('app.timezone', 'UTC'));
            $dayKey = $slot->toDateString();
            $timeKey = $slot->format('H:i');
            $counts[$dayKey][$timeKey] = (int) $row->occupied_staff_count;
        }

        return $counts;
    }

    /**
     * @param list<int> $staffUserIds
     * @param list<string> $candidateTimes
     * @return list<string>
     */
    public function availableStartTimesForDate(
        int $tenantId,
        int $locationId,
        CarbonImmutable $date,
        array $staffUserIds,
        array $candidateTimes,
        int $requiredDurationMinutes = 15,
        int $bufferBeforeMinutes = 0,
        int $bufferAfterMinutes = 0
    ): array {
        $availableByTime = $this->availableStaffByStartTimeForDate(
            $tenantId,
            $locationId,
            $date,
            $staffUserIds,
            $candidateTimes,
            $requiredDurationMinutes,
            $bufferBeforeMinutes,
            $bufferAfterMinutes
        );

        return array_keys($availableByTime);
    }

    /**
     * @param list<int> $staffUserIds
     * @param list<string> $candidateTimes
     * @return array<string, list<int>>
     */
    public function availableStaffByStartTimeForDate(
        int $tenantId,
        int $locationId,
        CarbonImmutable $date,
        array $staffUserIds,
        array $candidateTimes,
        int $requiredDurationMinutes = 15,
        int $bufferBeforeMinutes = 0,
        int $bufferAfterMinutes = 0
    ): array {
        $staffUserIds = collect($staffUserIds)
            ->map(static fn (int $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
        $candidateTimes = collect($candidateTimes)
            ->map(static fn (string $time): string => trim($time))
            ->filter(static fn (string $time): bool => preg_match('/^\d{2}:\d{2}$/', $time) === 1)
            ->unique()
            ->values()
            ->all();

        if ($tenantId <= 0 || $locationId <= 0 || $staffUserIds === [] || $candidateTimes === []) {
            return [];
        }

        $requiredDurationMinutes = max(15, $requiredDurationMinutes);
        $bufferBeforeMinutes = max(0, $bufferBeforeMinutes);
        $bufferAfterMinutes = max(0, $bufferAfterMinutes);
        $dateStart = $date->startOfDay();
        $dateEnd = $date->endOfDay();
        $occupiedByStaff = [];
        $rows = BookingSlot::query()
            ->where('tenant_id', $tenantId)
            ->where('location_id', $locationId)
            ->whereIn('staff_user_id', $staffUserIds)
            ->whereBetween('slot_start', [$dateStart->subDay(), $dateEnd->addDay()])
            ->get(['staff_user_id', 'slot_start']);

        foreach ($rows as $row) {
            $slotKey = $this->normalizeSlotKey($row->slot_start);

            if ($slotKey === null) {
                continue;
            }

            $staffId = (int) $row->staff_user_id;
            $occupiedByStaff[$staffId][$slotKey] = true;
        }

        $availableByTime = [];

        foreach ($candidateTimes as $time) {
            try {
                $slotStart = CarbonImmutable::createFromFormat(
                    'Y-m-d H:i',
                    $date->toDateString() . ' ' . $time,
                    $date->getTimezone()
                );
            } catch (\Throwable) {
                continue;
            }

            $slotEnd = $slotStart->addMinutes($requiredDurationMinutes);
            $blockStart = $slotStart->subMinutes($bufferBeforeMinutes);
            $blockEnd = $slotEnd->addMinutes($bufferAfterMinutes);
            $requiredSlots = $this->slotStartsForInterval($blockStart, $blockEnd);

            if ($requiredSlots === []) {
                continue;
            }

            $availableStaffIds = [];

            foreach ($staffUserIds as $staffUserId) {
                $occupiedSlots = $occupiedByStaff[$staffUserId] ?? [];
                $isFree = true;

                foreach ($requiredSlots as $requiredSlot) {
                    if (isset($occupiedSlots[$requiredSlot])) {
                        $isFree = false;
                        break;
                    }
                }

                if ($isFree) {
                    $availableStaffIds[] = (int) $staffUserId;
                }
            }

            if ($availableStaffIds !== []) {
                $availableByTime[$time] = $availableStaffIds;
            }
        }

        return $availableByTime;
    }

    /**
     * @return list<string>
     */
    public function slotStartsForInterval(CarbonImmutable $startsAt, CarbonImmutable $endsAt): array
    {
        if (! $startsAt->lt($endsAt)) {
            return [];
        }

        $cursor = $this->floorToQuarterHour($startsAt);
        $slotStarts = [];

        while ($cursor->lt($endsAt)) {
            $slotStarts[] = $cursor->format('Y-m-d H:i:s');
            $cursor = $cursor->addMinutes(15);
        }

        return $slotStarts;
    }

    private function floorToQuarterHour(CarbonInterface $dateTime): CarbonImmutable
    {
        $minutes = (int) $dateTime->format('i');
        $flooredMinutes = intdiv($minutes, 15) * 15;

        return CarbonImmutable::instance($dateTime)
            ->setMinute($flooredMinutes)
            ->setSecond(0)
            ->setMicrosecond(0);
    }

    private function normalizeSlotKey(mixed $slotStart): ?string
    {
        if ($slotStart instanceof CarbonInterface) {
            return CarbonImmutable::instance($slotStart)
                ->setSecond(0)
                ->setMicrosecond(0)
                ->format('Y-m-d H:i:s');
        }

        if (! is_string($slotStart) || trim($slotStart) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($slotStart, (string) config('app.timezone', 'UTC'))
                ->setSecond(0)
                ->setMicrosecond(0)
                ->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }
}
