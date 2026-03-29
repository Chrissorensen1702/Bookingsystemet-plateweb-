<?php

namespace App\Support;

use App\Models\LocationClosure;
use App\Models\LocationDateOverride;
use App\Models\LocationOpeningHour;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class LocationAvailability
{
    /**
     * @return Collection<int, array{opens_at: string, closes_at: string}>
     */
    public function intervalsForDate(int $locationId, CarbonImmutable $date): Collection
    {
        return $this->resolveIntervalsForDate($locationId, $date);
    }

    /**
     * @return list<string>
     */
    public function startTimesForDate(
        int $locationId,
        CarbonImmutable $date,
        int $slotMinutes = 15,
        int $requiredDurationMinutes = 15,
        int $bufferBeforeMinutes = 0,
        int $bufferAfterMinutes = 0
    ): array
    {
        $intervals = $this->resolveIntervalsForDate($locationId, $date);

        if ($intervals->isEmpty()) {
            return [];
        }

        $slotMinutes = max(1, $slotMinutes);
        $requiredDurationMinutes = max(1, $requiredDurationMinutes);
        $bufferBeforeMinutes = max(0, $bufferBeforeMinutes);
        $bufferAfterMinutes = max(0, $bufferAfterMinutes);
        $times = [];

        foreach ($intervals as $interval) {
            $opensAt = $this->minuteOfDayFromTime((string) $interval['opens_at']);
            $closesAt = $this->minuteOfDayFromTime((string) $interval['closes_at']);
            $candidateStartMinute = $opensAt + $bufferBeforeMinutes;
            $latestStartMinute = $closesAt - $requiredDurationMinutes - $bufferAfterMinutes;

            for ($minute = $candidateStartMinute; $minute <= $latestStartMinute; $minute += $slotMinutes) {
                $times[] = sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60);
            }
        }

        return array_values(array_unique($times));
    }

    public function allowsInterval(int $locationId, CarbonImmutable $startsAt, CarbonImmutable $endsAt): bool
    {
        if (! $startsAt->lt($endsAt)) {
            return false;
        }

        if (! $startsAt->isSameDay($endsAt)) {
            return false;
        }

        $intervals = $this->resolveIntervalsForDate($locationId, $startsAt);

        if ($intervals->isEmpty()) {
            return false;
        }

        $startMinutes = $this->minuteOfDayFromDateTime($startsAt);
        $endMinutes = $this->minuteOfDayFromDateTime($endsAt);

        foreach ($intervals as $interval) {
            $opensAt = $this->minuteOfDayFromTime((string) $interval['opens_at']);
            $closesAt = $this->minuteOfDayFromTime((string) $interval['closes_at']);

            if ($startMinutes >= $opensAt && $endMinutes <= $closesAt) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return Collection<int, array{opens_at: string, closes_at: string}>
     */
    private function resolveIntervalsForDate(int $locationId, CarbonImmutable $date): Collection
    {
        $dateString = $date->toDateString();

        $override = LocationDateOverride::query()
            ->where('location_id', $locationId)
            ->whereDate('override_date', $dateString)
            ->with([
                'slots' => fn ($query) => $query
                    ->orderBy('opens_at')
                    ->orderBy('id'),
            ])
            ->first();

        if ($override) {
            if ($override->is_closed) {
                return collect();
            }

            return $override->slots->map(static fn ($slot): array => [
                'opens_at' => (string) $slot->opens_at,
                'closes_at' => (string) $slot->closes_at,
            ]);
        }

        $hasClosure = LocationClosure::query()
            ->where('location_id', $locationId)
            ->whereDate('starts_on', '<=', $dateString)
            ->whereDate('ends_on', '>=', $dateString)
            ->exists();

        if ($hasClosure) {
            return collect();
        }

        return LocationOpeningHour::query()
            ->where('location_id', $locationId)
            ->where('weekday', (int) $date->isoWeekday())
            ->orderBy('opens_at')
            ->orderBy('id')
            ->get(['opens_at', 'closes_at'])
            ->map(static fn ($openingHour): array => [
                'opens_at' => (string) $openingHour->opens_at,
                'closes_at' => (string) $openingHour->closes_at,
            ]);
    }

    private function minuteOfDayFromDateTime(CarbonImmutable $dateTime): int
    {
        return ((int) $dateTime->format('H') * 60) + (int) $dateTime->format('i');
    }

    private function minuteOfDayFromTime(string $time): int
    {
        $parts = explode(':', $time);
        $hours = (int) ($parts[0] ?? 0);
        $minutes = (int) ($parts[1] ?? 0);

        return ($hours * 60) + $minutes;
    }
}
