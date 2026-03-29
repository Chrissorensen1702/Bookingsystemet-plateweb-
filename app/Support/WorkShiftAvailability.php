<?php

namespace App\Support;

use App\Models\UserWorkShift;
use Carbon\CarbonImmutable;

class WorkShiftAvailability
{
    /**
     * @param list<int> $userIds
     * @return array<int, list<array{start: int, end: int}>>
     */
    public function coverageByUserForDate(
        int $tenantId,
        int $locationId,
        CarbonImmutable $date,
        array $userIds,
        bool $requirePublic = false
    ): array {
        $userIds = collect($userIds)
            ->map(static fn (int $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($tenantId <= 0 || $locationId <= 0 || $userIds === []) {
            return [];
        }

        $query = UserWorkShift::query()
            ->where('tenant_id', $tenantId)
            ->where('location_id', $locationId)
            ->whereDate('shift_date', $date->toDateString())
            ->whereIn('user_id', $userIds)
            ->where('work_role', UserWorkShift::ROLE_SERVICE)
            ->orderBy('starts_at');

        if ($requirePublic) {
            $query->where('is_public', true);
        }

        $shifts = $query->get([
            'user_id',
            'starts_at',
            'ends_at',
            'break_starts_at',
            'break_ends_at',
        ]);

        $coverageByUser = [];

        foreach ($userIds as $userId) {
            $coverageByUser[$userId] = [];
        }

        foreach ($shifts as $shift) {
            $userId = (int) $shift->user_id;
            $segments = $this->segmentsForShift(
                (string) $shift->starts_at,
                (string) $shift->ends_at,
                $shift->break_starts_at ? (string) $shift->break_starts_at : null,
                $shift->break_ends_at ? (string) $shift->break_ends_at : null
            );

            if ($segments === []) {
                continue;
            }

            foreach ($segments as $segment) {
                $coverageByUser[$userId][] = $segment;
            }
        }

        foreach ($coverageByUser as $userId => $segments) {
            $coverageByUser[$userId] = $this->mergeSegments($segments);
        }

        return $coverageByUser;
    }

    public function userCoversIntervalForDate(
        int $tenantId,
        int $locationId,
        int $userId,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        bool $requirePublic = false
    ): bool {
        if (! $startsAt->lt($endsAt) || ! $startsAt->isSameDay($endsAt) || $userId <= 0) {
            return false;
        }

        $coverageByUser = $this->coverageByUserForDate(
            $tenantId,
            $locationId,
            $startsAt->startOfDay(),
            [$userId],
            $requirePublic
        );

        return $this->userCoversInterval($coverageByUser, $userId, $startsAt, $endsAt);
    }

    /**
     * @param array<int, list<array{start: int, end: int}>> $coverageByUser
     */
    public function userHasAnyCoverage(array $coverageByUser, int $userId): bool
    {
        return isset($coverageByUser[$userId]) && $coverageByUser[$userId] !== [];
    }

    /**
     * @param array<int, list<array{start: int, end: int}>> $coverageByUser
     */
    public function userCoversInterval(
        array $coverageByUser,
        int $userId,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt
    ): bool {
        if (! $startsAt->lt($endsAt) || ! $startsAt->isSameDay($endsAt)) {
            return false;
        }

        $startMinute = ((int) $startsAt->format('H') * 60) + (int) $startsAt->format('i');
        $endMinute = ((int) $endsAt->format('H') * 60) + (int) $endsAt->format('i');

        return $this->userCoversMinuteInterval($coverageByUser, $userId, $startMinute, $endMinute);
    }

    /**
     * @param array<int, list<array{start: int, end: int}>> $coverageByUser
     */
    public function userCoversMinuteInterval(
        array $coverageByUser,
        int $userId,
        int $startMinute,
        int $endMinute
    ): bool {
        if ($startMinute < 0 || $endMinute <= $startMinute || $endMinute > 24 * 60) {
            return false;
        }

        $segments = $coverageByUser[$userId] ?? [];

        foreach ($segments as $segment) {
            if ($startMinute >= $segment['start'] && $endMinute <= $segment['end']) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{start: int, end: int}>
     */
    private function segmentsForShift(
        string $startsAt,
        string $endsAt,
        ?string $breakStartsAt,
        ?string $breakEndsAt
    ): array {
        $shiftStart = $this->minuteOfDayFromTime($startsAt);
        $shiftEnd = $this->minuteOfDayFromTime($endsAt);

        if ($shiftStart === null || $shiftEnd === null || $shiftEnd <= $shiftStart) {
            return [];
        }

        $breakStart = $this->minuteOfDayFromTime($breakStartsAt);
        $breakEnd = $this->minuteOfDayFromTime($breakEndsAt);

        if (
            $breakStart === null ||
            $breakEnd === null ||
            $breakEnd <= $breakStart ||
            $breakStart >= $shiftEnd ||
            $breakEnd <= $shiftStart
        ) {
            return [
                ['start' => $shiftStart, 'end' => $shiftEnd],
            ];
        }

        $normalizedBreakStart = max($shiftStart, $breakStart);
        $normalizedBreakEnd = min($shiftEnd, $breakEnd);
        $segments = [];

        if ($normalizedBreakStart > $shiftStart) {
            $segments[] = ['start' => $shiftStart, 'end' => $normalizedBreakStart];
        }

        if ($normalizedBreakEnd < $shiftEnd) {
            $segments[] = ['start' => $normalizedBreakEnd, 'end' => $shiftEnd];
        }

        return $segments;
    }

    /**
     * @param list<array{start: int, end: int}> $segments
     * @return list<array{start: int, end: int}>
     */
    private function mergeSegments(array $segments): array
    {
        if ($segments === []) {
            return [];
        }

        usort($segments, static function (array $a, array $b): int {
            if ($a['start'] === $b['start']) {
                return $a['end'] <=> $b['end'];
            }

            return $a['start'] <=> $b['start'];
        });

        $merged = [];

        foreach ($segments as $segment) {
            if ($merged === []) {
                $merged[] = $segment;
                continue;
            }

            $lastIndex = count($merged) - 1;
            $last = $merged[$lastIndex];

            if ($segment['start'] <= $last['end']) {
                $merged[$lastIndex]['end'] = max($last['end'], $segment['end']);
                continue;
            }

            $merged[] = $segment;
        }

        return $merged;
    }

    private function minuteOfDayFromTime(?string $time): ?int
    {
        if (! is_string($time) || trim($time) === '') {
            return null;
        }

        $parts = explode(':', trim($time));
        $hours = (int) ($parts[0] ?? -1);
        $minutes = (int) ($parts[1] ?? -1);

        if ($hours < 0 || $hours > 23 || $minutes < 0 || $minutes > 59) {
            return null;
        }

        return ($hours * 60) + $minutes;
    }
}
