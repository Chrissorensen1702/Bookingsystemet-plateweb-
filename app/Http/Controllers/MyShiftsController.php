<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\User;
use App\Models\UserWorkShift;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MyShiftsController extends Controller
{
    public function __invoke(Request $request): View
    {
        $tenantId = $this->resolveTenantId($request);
        abort_if($tenantId <= 0, 500, 'Ingen aktiv tenant er konfigureret.');

        /** @var User|null $user */
        $user = $request->user();
        abort_if(! $user instanceof User || (int) $user->tenant_id !== $tenantId, 403);
        $workShiftsEnabled = (bool) (Tenant::query()
            ->whereKey($tenantId)
            ->value('work_shifts_enabled') ?? true);

        if (! $workShiftsEnabled) {
            return view('my-shifts', [
                'workShiftsEnabled' => false,
                'upcomingShiftsByDate' => [],
                'upcomingShiftCount' => 0,
                'countdownPrefix' => 'Næste vagt starter om',
                'countdownTargetIso' => null,
            ]);
        }

        $accessibleLocationIds = $this->locationScopeForRequest($request, $tenantId)
            ->pluck('id')
            ->map(static fn (int $id): int => $id)
            ->all();

        $timezone = (string) config('app.timezone', 'UTC');
        $now = CarbonImmutable::now($timezone);
        $todayDate = $now->toDateString();
        $currentTime = $now->format('H:i:s');

        $upcomingShiftsQuery = UserWorkShift::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', (int) $user->id)
            ->where('is_public', true)
            ->where(function ($query) use ($todayDate, $currentTime): void {
                $query
                    ->whereDate('shift_date', '>', $todayDate)
                    ->orWhere(function ($todayQuery) use ($todayDate, $currentTime): void {
                        $todayQuery
                            ->whereDate('shift_date', '=', $todayDate)
                            ->whereTime('ends_at', '>', $currentTime);
                    });
            })
            ->with(['location:id,name']);

        if (! $user->isOwner()) {
            if ($accessibleLocationIds === []) {
                $upcomingShiftsQuery->whereRaw('1 = 0');
            } else {
                $upcomingShiftsQuery->whereIn('location_id', $accessibleLocationIds);
            }
        }

        $upcomingShifts = $upcomingShiftsQuery
            ->orderBy('shift_date')
            ->orderBy('starts_at')
            ->get([
                'id',
                'tenant_id',
                'location_id',
                'user_id',
                'shift_date',
                'starts_at',
                'ends_at',
                'break_starts_at',
                'break_ends_at',
                'work_role',
                'notes',
                'is_public',
            ]);

        $upcomingShiftsByDate = $upcomingShifts
            ->groupBy(static fn (UserWorkShift $shift): string => $shift->shift_date->format('Y-m-d'))
            ->map(static fn ($shifts) => $shifts->values())
            ->all();
        $nextShift = $upcomingShifts->first(static function (UserWorkShift $shift) use ($now, $timezone): bool {
            $shiftStart = CarbonImmutable::createFromFormat(
                'Y-m-d H:i:s',
                $shift->shift_date->format('Y-m-d').' '.$shift->starts_at,
                $timezone
            );

            return $shiftStart->greaterThan($now);
        });

        $countdownPrefix = 'Næste vagt starter om';
        $countdownTargetIso = null;

        if ($nextShift instanceof UserWorkShift) {
            $countdownTargetIso = CarbonImmutable::createFromFormat(
                'Y-m-d H:i:s',
                $nextShift->shift_date->format('Y-m-d').' '.$nextShift->starts_at,
                $timezone
            )->toIso8601String();
        } else {
            $ongoingShift = $upcomingShifts->first();

            if ($ongoingShift instanceof UserWorkShift) {
                $countdownPrefix = 'Aktuel vagt slutter om';
                $countdownTargetIso = CarbonImmutable::createFromFormat(
                    'Y-m-d H:i:s',
                    $ongoingShift->shift_date->format('Y-m-d').' '.$ongoingShift->ends_at,
                    $timezone
                )->toIso8601String();
            }
        }

        return view('my-shifts', [
            'workShiftsEnabled' => true,
            'upcomingShiftsByDate' => $upcomingShiftsByDate,
            'upcomingShiftCount' => (int) $upcomingShifts->count(),
            'countdownPrefix' => $countdownPrefix,
            'countdownTargetIso' => $countdownTargetIso,
        ]);
    }
}
