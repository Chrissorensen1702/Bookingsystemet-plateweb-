<?php

namespace App\Http\Controllers;

use App\Models\LocationClosure;
use App\Models\LocationDateOverride;
use App\Models\LocationDateOverrideSlot;
use App\Models\LocationOpeningHour;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AvailabilityController extends Controller
{
    private const WEEK_DAYS = [
        1 => 'Mandag',
        2 => 'Tirsdag',
        3 => 'Onsdag',
        4 => 'Torsdag',
        5 => 'Fredag',
        6 => 'Lørdag',
        7 => 'Søndag',
    ];

    public function index(Request $request): View
    {
        $tenantId = $this->resolveTenantId($request);
        abort_if($tenantId <= 0, 500, 'Ingen aktiv tenant er konfigureret.');

        $locationId = $this->resolveLocationId($request, $tenantId);
        abort_if($locationId <= 0, 500, 'Ingen aktiv lokation er konfigureret.');

        $locations = $this->locationScopeForRequest($request, $tenantId)
            ->orderBy('name')
            ->get(['id', 'name']);

        $openingHoursByDay = LocationOpeningHour::query()
            ->where('location_id', $locationId)
            ->orderBy('weekday')
            ->orderBy('opens_at')
            ->get()
            ->groupBy('weekday');

        $closures = LocationClosure::query()
            ->where('location_id', $locationId)
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->get();

        $dateOverrides = LocationDateOverride::query()
            ->where('location_id', $locationId)
            ->with([
                'slots' => fn ($query) => $query
                    ->orderBy('opens_at')
                    ->orderBy('id'),
            ])
            ->orderBy('override_date')
            ->orderBy('id')
            ->get();

        return view('availability', [
            'locations' => $locations,
            'selectedLocationId' => $locationId,
            'weekDays' => self::WEEK_DAYS,
            'openingHoursByDay' => $openingHoursByDay,
            'closures' => $closures,
            'dateOverrides' => $dateOverrides,
        ]);
    }

    public function storeOpeningHour(Request $request): RedirectResponse
    {
        $tenantId = $this->resolveTenantId($request);
        abort_if($tenantId <= 0, 500, 'Ingen aktiv tenant er konfigureret.');

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
            'weekday' => ['required', 'integer', 'between:1,7'],
            'opens_at' => ['required', 'date_format:H:i'],
            'closes_at' => ['required', 'date_format:H:i', 'after:opens_at'],
        ]);

        $locationId = (int) $validated['location_id'];
        abort_if(! $this->canAccessLocation($request, $tenantId, $locationId), 404);
        $weekday = (int) $validated['weekday'];
        $opensAt = $this->normalizeTime((string) $validated['opens_at']);
        $closesAt = $this->normalizeTime((string) $validated['closes_at']);

        $hasOverlap = LocationOpeningHour::query()
            ->where('location_id', $locationId)
            ->where('weekday', $weekday)
            ->where('opens_at', '<', $closesAt)
            ->where('closes_at', '>', $opensAt)
            ->exists();

        if ($hasOverlap) {
            return $this->redirectToIndex($locationId)
                ->withErrors(['availability' => 'Tidsintervallet overlapper en eksisterende åbningstid.'])
                ->withInput();
        }

        LocationOpeningHour::query()->create([
            'location_id' => $locationId,
            'weekday' => $weekday,
            'opens_at' => $opensAt,
            'closes_at' => $closesAt,
        ]);

        return $this->redirectToIndex($locationId)
            ->with('status', 'Åbningstiden er oprettet.');
    }

    public function destroyOpeningHour(Request $request, LocationOpeningHour $openingHour): RedirectResponse
    {
        $tenantId = $this->resolveTenantId($request);
        abort_if($tenantId <= 0, 500, 'Ingen aktiv tenant er konfigureret.');

        $openingHour->loadMissing('location:id,tenant_id');
        abort_if((int) ($openingHour->location?->tenant_id ?? 0) !== $tenantId, 404);

        $locationId = (int) $openingHour->location_id;
        abort_if(! $this->canAccessLocation($request, $tenantId, $locationId), 404);
        $openingHour->delete();

        return $this->redirectToIndex($locationId)
            ->with('status', 'Åbningstiden er slettet.');
    }

    public function storeClosure(Request $request): RedirectResponse
    {
        $tenantId = $this->resolveTenantId($request);
        abort_if($tenantId <= 0, 500, 'Ingen aktiv tenant er konfigureret.');

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
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'reason' => ['nullable', 'string', 'max:180'],
        ]);

        $locationId = (int) $validated['location_id'];
        abort_if(! $this->canAccessLocation($request, $tenantId, $locationId), 404);

        LocationClosure::query()->create([
            'location_id' => $locationId,
            'starts_on' => $validated['starts_on'],
            'ends_on' => $validated['ends_on'],
            'reason' => filled($validated['reason'] ?? null)
                ? trim((string) $validated['reason'])
                : null,
        ]);

        return $this->redirectToIndex($locationId)
            ->with('status', 'Lukkeperioden er oprettet.');
    }

    public function destroyClosure(Request $request, LocationClosure $closure): RedirectResponse
    {
        $tenantId = $this->resolveTenantId($request);
        abort_if($tenantId <= 0, 500, 'Ingen aktiv tenant er konfigureret.');

        $closure->loadMissing('location:id,tenant_id');
        abort_if((int) ($closure->location?->tenant_id ?? 0) !== $tenantId, 404);

        $locationId = (int) $closure->location_id;
        abort_if(! $this->canAccessLocation($request, $tenantId, $locationId), 404);
        $closure->delete();

        return $this->redirectToIndex($locationId)
            ->with('status', 'Lukkeperioden er slettet.');
    }

    public function storeDateOverride(Request $request): RedirectResponse
    {
        $tenantId = $this->resolveTenantId($request);
        abort_if($tenantId <= 0, 500, 'Ingen aktiv tenant er konfigureret.');

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
            'override_date' => ['required', 'date'],
            'override_type' => ['required', Rule::in(['closed', 'open'])],
            'opens_at' => ['nullable', 'date_format:H:i', 'required_if:override_type,open'],
            'closes_at' => ['nullable', 'date_format:H:i', 'required_if:override_type,open', 'after:opens_at'],
            'note' => ['nullable', 'string', 'max:180'],
        ]);

        $locationId = (int) $validated['location_id'];
        abort_if(! $this->canAccessLocation($request, $tenantId, $locationId), 404);
        $date = (string) $validated['override_date'];
        $type = (string) $validated['override_type'];

        $existingOverride = LocationDateOverride::query()
            ->where('location_id', $locationId)
            ->whereDate('override_date', $date)
            ->exists();

        if ($existingOverride) {
            return $this->redirectToIndex($locationId)
                ->withErrors(['availability' => 'Der findes allerede en dato-undtagelse på den valgte dato.'])
                ->withInput();
        }

        if ($type === 'open') {
            $opensAt = $this->normalizeTime((string) $validated['opens_at']);
            $closesAt = $this->normalizeTime((string) $validated['closes_at']);

            DB::transaction(function () use ($locationId, $date, $validated, $opensAt, $closesAt): void {
                $override = LocationDateOverride::query()->create([
                    'location_id' => $locationId,
                    'override_date' => $date,
                    'is_closed' => false,
                    'note' => filled($validated['note'] ?? null)
                        ? trim((string) $validated['note'])
                        : null,
                ]);

                $override->slots()->create([
                    'opens_at' => $opensAt,
                    'closes_at' => $closesAt,
                ]);
            });
        } else {
            LocationDateOverride::query()->create([
                'location_id' => $locationId,
                'override_date' => $date,
                'is_closed' => true,
                'note' => filled($validated['note'] ?? null)
                    ? trim((string) $validated['note'])
                    : null,
            ]);
        }

        return $this->redirectToIndex($locationId)
            ->with('status', 'Dato-undtagelsen er oprettet.');
    }

    public function destroyDateOverride(Request $request, LocationDateOverride $override): RedirectResponse
    {
        $tenantId = $this->resolveTenantId($request);
        abort_if($tenantId <= 0, 500, 'Ingen aktiv tenant er konfigureret.');

        $override->loadMissing('location:id,tenant_id');
        abort_if((int) ($override->location?->tenant_id ?? 0) !== $tenantId, 404);

        $locationId = (int) $override->location_id;
        abort_if(! $this->canAccessLocation($request, $tenantId, $locationId), 404);
        $override->delete();

        return $this->redirectToIndex($locationId)
            ->with('status', 'Dato-undtagelsen er slettet.');
    }

    public function storeDateOverrideSlot(Request $request, LocationDateOverride $override): RedirectResponse
    {
        $tenantId = $this->resolveTenantId($request);
        abort_if($tenantId <= 0, 500, 'Ingen aktiv tenant er konfigureret.');

        $override->loadMissing('location:id,tenant_id');
        abort_if((int) ($override->location?->tenant_id ?? 0) !== $tenantId, 404);

        $locationId = (int) $override->location_id;
        abort_if(! $this->canAccessLocation($request, $tenantId, $locationId), 404);

        if ($override->is_closed) {
            return $this->redirectToIndex($locationId)
                ->withErrors(['availability' => 'Lukkede dato-undtagelser kan ikke have tidsintervaller.']);
        }

        $validated = $request->validate([
            'opens_at' => ['required', 'date_format:H:i'],
            'closes_at' => ['required', 'date_format:H:i', 'after:opens_at'],
        ]);

        $opensAt = $this->normalizeTime((string) $validated['opens_at']);
        $closesAt = $this->normalizeTime((string) $validated['closes_at']);

        $hasOverlap = $override->slots()
            ->where('opens_at', '<', $closesAt)
            ->where('closes_at', '>', $opensAt)
            ->exists();

        if ($hasOverlap) {
            return $this->redirectToIndex($locationId)
                ->withErrors(['availability' => 'Tidsintervallet overlapper et eksisterende slot på datoen.'])
                ->withInput();
        }

        $override->slots()->create([
            'opens_at' => $opensAt,
            'closes_at' => $closesAt,
        ]);

        return $this->redirectToIndex($locationId)
            ->with('status', 'Ekstra tidsinterval er oprettet.');
    }

    public function destroyDateOverrideSlot(Request $request, LocationDateOverrideSlot $overrideSlot): RedirectResponse
    {
        $tenantId = $this->resolveTenantId($request);
        abort_if($tenantId <= 0, 500, 'Ingen aktiv tenant er konfigureret.');

        $overrideSlot->loadMissing('override.location:id,tenant_id');
        $override = $overrideSlot->override;

        abort_if((int) ($override?->location?->tenant_id ?? 0) !== $tenantId, 404);

        $locationId = (int) ($override?->location_id ?? 0);
        abort_if($locationId <= 0, 404);
        abort_if(! $this->canAccessLocation($request, $tenantId, $locationId), 404);

        $slotCount = (int) ($override?->slots()->count() ?? 0);

        if ($slotCount <= 1) {
            return $this->redirectToIndex($locationId)
                ->withErrors(['availability' => 'En åben dato-undtagelse skal have mindst et tidsinterval.']);
        }

        $overrideSlot->delete();

        return $this->redirectToIndex($locationId)
            ->with('status', 'Tidsintervallet er slettet.');
    }

    private function redirectToIndex(int $locationId): RedirectResponse
    {
        return redirect()->route('availability.index', [
            'location_id' => $locationId,
        ]);
    }

    private function normalizeTime(string $time): string
    {
        return strlen($time) === 5
            ? $time.':00'
            : $time;
    }
}
