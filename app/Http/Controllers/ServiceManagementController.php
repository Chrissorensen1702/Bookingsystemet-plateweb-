<?php

namespace App\Http\Controllers;

use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ServiceManagementController extends Controller
{
    public function index(Request $request): View
    {
        $tenantId = $this->resolveTenantId($request);
        abort_if($tenantId <= 0, 500, 'Ingen aktiv tenant er konfigureret.');

        $locations = $this->locationScopeForRequest($request, $tenantId)
            ->orderBy('name')
            ->get(['id', 'name', 'slug']);
        $requiresServiceCategories = (bool) (Tenant::query()
            ->whereKey($tenantId)
            ->value('require_service_categories') ?? true);
        $accessibleLocationIds = $locations
            ->pluck('id')
            ->map(static fn (int $id): int => $id)
            ->all();

        return view('services', [
            'services' => Service::query()
                ->where('services.tenant_id', $tenantId)
                ->leftJoin('service_categories', 'service_categories.id', '=', 'services.service_category_id')
                ->select('services.*')
                ->with('category')
                ->with([
                    'locations' => fn ($query) => $query
                        ->select('locations.id')
                        ->whereIn('locations.id', $accessibleLocationIds),
                ])
                ->withCount('bookings')
                ->withCount([
                    'locations as active_locations_count' => fn ($query) => $query
                        ->whereIn('locations.id', $accessibleLocationIds)
                        ->where('location_service.is_active', true),
                ])
                ->orderBy('services.sort_order')
                ->orderBy('services.name')
                ->orderBy('service_categories.name')
                ->get(),
            'locations' => $locations,
            'serviceCategories' => ServiceCategory::query()
                ->where('tenant_id', $tenantId)
                ->withCount('services')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(['id', 'name', 'description', 'sort_order']),
            'requiresServiceCategories' => $requiresServiceCategories,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $tenantId = $this->resolveTenantId($request);
        abort_if($tenantId <= 0, 500, 'Ingen aktiv tenant er konfigureret.');
        $locationIds = $this->resolveAccessibleLocationIds($request, $tenantId);

        $payload = $this->validatePayload($request, $tenantId, $locationIds);
        $payload['tenant_id'] = $tenantId;
        $requestedSortOrder = max(1, (int) ($payload['sort_order'] ?? 1));

        DB::transaction(function () use (
            $payload,
            $tenantId,
            $requestedSortOrder,
            $request,
            $locationIds
        ): void {
            $service = Service::query()->create($payload);
            $this->syncServiceLocationAssignments($request, $service, $locationIds);
            $this->reorderGlobalServices($tenantId, (int) $service->id, $requestedSortOrder);
            $this->applyRequestedLocalSortOrders($request, $tenantId, $service, $locationIds);
        });

        return redirect()
            ->route('services.index')
            ->with('status', 'Ydelsen er oprettet.');
    }

    public function update(Request $request, Service $service): RedirectResponse
    {
        $tenantId = $this->resolveTenantId($request);
        abort_if($tenantId <= 0, 500, 'Ingen aktiv tenant er konfigureret.');
        abort_if((int) $service->tenant_id !== $tenantId, 404);
        $locationIds = $this->resolveAccessibleLocationIds($request, $tenantId);

        $payload = $this->validatePayload($request, $tenantId, $locationIds, $service);
        $requestedSortOrder = max(1, (int) ($payload['sort_order'] ?? $service->sort_order));

        DB::transaction(function () use (
            $service,
            $payload,
            $request,
            $locationIds,
            $tenantId,
            $requestedSortOrder
        ): void {
            $service->update($payload);
            $this->syncServiceLocationAssignments($request, $service, $locationIds);
            $this->reorderGlobalServices($tenantId, (int) $service->id, $requestedSortOrder);
            $this->applyRequestedLocalSortOrders($request, $tenantId, $service, $locationIds);
        });

        return redirect()
            ->route('services.index')
            ->with('status', 'Ydelsen er opdateret.');
    }

    public function toggleActive(Request $request, Service $service): RedirectResponse
    {
        $tenantId = $this->resolveTenantId($request);
        abort_if($tenantId <= 0, 500, 'Ingen aktiv tenant er konfigureret.');
        abort_if((int) $service->tenant_id !== $tenantId, 404);

        $locationIds = $this->resolveAccessibleLocationIds($request, $tenantId);

        if ($locationIds === []) {
            return redirect()
                ->route('services.index')
                ->withErrors(['service_toggle' => 'Ingen aktive lokationer fundet.']);
        }

        $hasAnyActiveLocation = $service->locations()
            ->whereIn('locations.id', $locationIds)
            ->wherePivot('is_active', true)
            ->exists();

        $nextState = ! $hasAnyActiveLocation;
        $syncPayload = [];

        foreach ($locationIds as $locationId) {
            $syncPayload[$locationId] = [
                'is_active' => $nextState,
            ];
        }

        $service->locations()->syncWithoutDetaching($syncPayload);

        return redirect()
            ->route('services.index')
            ->with('status', $nextState
                ? 'Ydelsen er slået til på alle lokationer.'
                : 'Ydelsen er slået fra på alle lokationer.');
    }

    public function toggleOnlineBookable(Request $request, Service $service): RedirectResponse
    {
        $tenantId = $this->resolveTenantId($request);
        abort_if($tenantId <= 0, 500, 'Ingen aktiv tenant er konfigureret.');
        abort_if((int) $service->tenant_id !== $tenantId, 404);

        $nextState = ! (bool) $service->is_online_bookable;
        $service->update([
            'is_online_bookable' => $nextState,
        ]);

        return redirect()
            ->route('services.index')
            ->with('status', $nextState
                ? 'Ydelsen kan nu bookes online.'
                : 'Ydelsen er fjernet fra online booking.');
    }

    public function destroy(Request $request, Service $service): RedirectResponse
    {
        $tenantId = $this->resolveTenantId($request);
        abort_if($tenantId <= 0, 500, 'Ingen aktiv tenant er konfigureret.');
        abort_if((int) $service->tenant_id !== $tenantId, 404);

        if ($service->bookings()->exists()) {
            return $this->redirectToEditService($request, $service, 'Ydelser med eksisterende bookinger kan ikke slettes.');
        }

        $service->delete();

        return redirect()
            ->route('services.index')
            ->with('status', 'Ydelsen er slettet.');
    }

    public function storeCategory(Request $request): RedirectResponse
    {
        $tenantId = $this->resolveTenantId($request);
        abort_if($tenantId <= 0, 500, 'Ingen aktiv tenant er konfigureret.');

        $validated = $request->validate([
            'form_scope' => ['nullable', 'string'],
            'category_name' => [
                'required',
                'string',
                'max:120',
                Rule::unique('service_categories', 'name')
                    ->where(fn ($query) => $query->where('tenant_id', $tenantId)),
            ],
            'category_description' => ['nullable', 'string', 'max:255'],
            'category_sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);

        $nextSortOrder = ((int) ServiceCategory::query()
            ->where('tenant_id', $tenantId)
            ->max('sort_order')) + 1;

        ServiceCategory::query()->create([
            'tenant_id' => $tenantId,
            'name' => trim((string) $validated['category_name']),
            'description' => filled($validated['category_description'] ?? null)
                ? trim((string) $validated['category_description'])
                : null,
            'sort_order' => filled($validated['category_sort_order'] ?? null)
                ? max(0, (int) $validated['category_sort_order'])
                : max(0, $nextSortOrder),
            'is_active' => true,
        ]);

        return redirect()
            ->route('services.index')
            ->with('status', 'Kategorien er oprettet.');
    }

    public function updateCategory(Request $request, ServiceCategory $serviceCategory): RedirectResponse
    {
        $tenantId = $this->resolveTenantId($request);
        abort_if($tenantId <= 0, 500, 'Ingen aktiv tenant er konfigureret.');
        abort_if((int) $serviceCategory->tenant_id !== $tenantId, 404);

        $validated = $request->validate([
            'form_scope' => ['nullable', 'string'],
            'category_modal_id' => ['nullable', 'integer'],
            'category_name' => [
                'required',
                'string',
                'max:120',
                Rule::unique('service_categories', 'name')
                    ->where(fn ($query) => $query->where('tenant_id', $tenantId))
                    ->ignore($serviceCategory->id),
            ],
            'category_description' => ['nullable', 'string', 'max:255'],
            'category_sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);

        $serviceCategory->update([
            'name' => trim((string) $validated['category_name']),
            'description' => filled($validated['category_description'] ?? null)
                ? trim((string) $validated['category_description'])
                : null,
            'sort_order' => filled($validated['category_sort_order'] ?? null)
                ? max(0, (int) $validated['category_sort_order'])
                : (int) $serviceCategory->sort_order,
        ]);

        return redirect()
            ->route('services.index')
            ->with('status', 'Kategorien er opdateret.');
    }

    public function destroyCategory(Request $request, ServiceCategory $serviceCategory): RedirectResponse
    {
        $tenantId = $this->resolveTenantId($request);
        abort_if($tenantId <= 0, 500, 'Ingen aktiv tenant er konfigureret.');
        abort_if((int) $serviceCategory->tenant_id !== $tenantId, 404);

        if ($serviceCategory->services()->exists()) {
            return redirect()
                ->route('services.index')
                ->withErrors([
                    'category_modal' => 'Kategorien bruges allerede af en eller flere ydelser og kan ikke slettes.',
                ])
                ->withInput([
                    'form_scope' => 'category_delete',
                    'category_modal_id' => (string) $serviceCategory->id,
                ]);
        }

        $serviceCategory->delete();

        return redirect()
            ->route('services.index')
            ->with('status', 'Kategorien er slettet.');
    }

    /**
     * @param list<int> $allowedLocationIds
     */
    private function validatePayload(
        Request $request,
        int $tenantId,
        array $allowedLocationIds,
        ?Service $service = null
    ): array
    {
        $requiresServiceCategories = (bool) (Tenant::query()
            ->whereKey($tenantId)
            ->value('require_service_categories') ?? true);

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('services', 'name')
                    ->where(fn ($query) => $query->where('tenant_id', $tenantId))
                    ->ignore($service?->id),
            ],
            'duration_minutes' => ['required', 'integer', 'min:5', 'max:480'],
            'price_kr' => ['nullable', 'string', 'max:20'],
            'is_online_bookable' => ['nullable', 'boolean'],
            'requires_staff_selection' => ['nullable', 'boolean'],
            'service_category_id' => $requiresServiceCategories
                ? [
                    'required',
                    'integer',
                    Rule::exists('service_categories', 'id')
                        ->where(fn ($query) => $query->where('tenant_id', $tenantId)),
                ]
                : [
                    'nullable',
                    'integer',
                    Rule::exists('service_categories', 'id')
                        ->where(fn ($query) => $query->where('tenant_id', $tenantId)),
                ],
            'sort_order' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'buffer_before_minutes' => ['nullable', 'integer', 'min:0', 'max:240'],
            'buffer_after_minutes' => ['nullable', 'integer', 'min:0', 'max:240'],
            'min_notice_minutes' => ['nullable', 'integer', 'min:0', 'max:10080'],
            'max_advance_days' => ['nullable', 'integer', 'min:1', 'max:730'],
            'cancellation_notice_hours' => ['nullable', 'integer', 'min:0', 'max:720'],
            'color' => ['required', 'string', 'regex:/^#[A-Fa-f0-9]{6}$/'],
            'description' => ['nullable', 'string', 'max:1000'],
            'has_location_selection' => ['sometimes', 'boolean'],
            'active_location_ids' => ['sometimes', 'array'],
            'active_location_ids.*' => [
                'integer',
                Rule::in($allowedLocationIds),
            ],
            'location_duration_minutes' => ['sometimes', 'array'],
            'location_duration_minutes.*' => ['nullable', 'integer', 'min:5', 'max:480'],
            'location_price_kr' => ['sometimes', 'array'],
            'location_price_kr.*' => ['nullable', 'string', 'max:20'],
            'location_sort_order' => ['sometimes', 'array'],
            'location_sort_order.*' => ['nullable', 'integer', 'min:1', 'max:9999'],
        ]);

        $validated['name'] = trim($validated['name']);
        $validated['price_minor'] = $this->parsePriceKrToMinor($validated['price_kr'] ?? null);
        $validated['is_online_bookable'] = (bool) ($validated['is_online_bookable'] ?? false);
        $validated['requires_staff_selection'] = (bool) ($validated['requires_staff_selection'] ?? true);
        $serviceCategoryId = max(0, (int) ($validated['service_category_id'] ?? 0));
        $validated['service_category_id'] = $serviceCategoryId > 0
            ? $serviceCategoryId
            : $this->resolveDefaultServiceCategoryId($tenantId);
        $validated['sort_order'] = max(1, (int) ($validated['sort_order'] ?? 1));
        $validated['buffer_before_minutes'] = max(0, (int) ($validated['buffer_before_minutes'] ?? 0));
        $validated['buffer_after_minutes'] = max(0, (int) ($validated['buffer_after_minutes'] ?? 0));
        $validated['min_notice_minutes'] = max(0, (int) ($validated['min_notice_minutes'] ?? 0));
        $validated['max_advance_days'] = filled($validated['max_advance_days'] ?? null)
            ? max(1, (int) $validated['max_advance_days'])
            : null;
        $validated['cancellation_notice_hours'] = max(0, (int) ($validated['cancellation_notice_hours'] ?? 24));
        $validated['color'] = $this->normalizeHexColor((string) $validated['color']) ?? '#5C80BC';
        $validated['description'] = filled($validated['description'] ?? null)
            ? trim($validated['description'])
            : null;
        unset($validated['price_kr']);

        return $validated;
    }

    /**
     * @param list<int> $locationIds
     */
    private function syncServiceLocationAssignments(Request $request, Service $service, array $locationIds): void
    {
        if ($locationIds === []) {
            return;
        }
        $hasExplicitSelection = $request->boolean('has_location_selection');
        $activeLocationIds = $hasExplicitSelection
            ? $this->resolveActiveLocationIdsFromRequest($request, $locationIds)
            : $locationIds;
        $durationOverridesByLocation = $this->resolveLocationDurationOverrides($request, $locationIds, $service);
        $priceOverridesByLocation = $this->resolveLocationPriceOverrides($request, $locationIds);
        $sortOrderOverridesByLocation = $this->resolveLocationSortOrderOverrides($request, $locationIds);

        $basePayload = $this->buildLocationServicePayload($service);
        $syncPayload = [];

        foreach ($locationIds as $locationId) {
            $durationOverride = $durationOverridesByLocation[$locationId] ?? null;
            $priceOverride = $priceOverridesByLocation[$locationId] ?? null;
            $sortOrderOverride = $sortOrderOverridesByLocation[$locationId] ?? null;

            $syncPayload[$locationId] = [
                ...$basePayload,
                'duration_minutes' => $durationOverride,
                'price_minor' => $priceOverride,
                'sort_order' => $sortOrderOverride,
                'is_active' => in_array($locationId, $activeLocationIds, true),
            ];
        }

        $service->locations()->syncWithoutDetaching($syncPayload);
    }

    /**
     * @param list<int> $allowedLocationIds
     * @return list<int>
     */
    private function resolveActiveLocationIdsFromRequest(Request $request, array $allowedLocationIds): array
    {
        return collect($request->input('active_location_ids', []))
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => in_array($id, $allowedLocationIds, true))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param list<int> $allowedLocationIds
     * @return array<int, int|null>
     */
    private function resolveLocationDurationOverrides(
        Request $request,
        array $allowedLocationIds,
        Service $service
    ): array {
        $raw = $request->input('location_duration_minutes', []);

        if (! is_array($raw)) {
            return [];
        }

        $serviceDuration = max(5, (int) $service->duration_minutes);
        $result = [];

        foreach ($raw as $locationId => $value) {
            $locationIdInt = (int) $locationId;

            if (! in_array($locationIdInt, $allowedLocationIds, true)) {
                continue;
            }

            $normalizedValue = trim((string) $value);

            if ($normalizedValue === '') {
                $result[$locationIdInt] = null;
                continue;
            }

            $durationMinutes = max(5, (int) $normalizedValue);
            $result[$locationIdInt] = $durationMinutes === $serviceDuration
                ? null
                : $durationMinutes;
        }

        return $result;
    }

    /**
     * @param list<int> $allowedLocationIds
     * @return array<int, int|null>
     */
    private function resolveLocationPriceOverrides(Request $request, array $allowedLocationIds): array
    {
        $raw = $request->input('location_price_kr', []);

        if (! is_array($raw)) {
            return [];
        }

        $result = [];

        foreach ($raw as $locationId => $value) {
            $locationIdInt = (int) $locationId;

            if (! in_array($locationIdInt, $allowedLocationIds, true)) {
                continue;
            }

            $result[$locationIdInt] = $this->parsePriceKrToMinor($value, 'location_price_kr.' . $locationIdInt);
        }

        return $result;
    }

    /**
     * @param list<int> $allowedLocationIds
     * @return array<int, int|null>
     */
    private function resolveLocationSortOrderOverrides(Request $request, array $allowedLocationIds): array
    {
        $raw = $request->input('location_sort_order', []);

        if (! is_array($raw)) {
            return [];
        }

        $result = [];

        foreach ($raw as $locationId => $value) {
            $locationIdInt = (int) $locationId;

            if (! in_array($locationIdInt, $allowedLocationIds, true)) {
                continue;
            }

            $normalizedValue = trim((string) $value);

            if ($normalizedValue === '') {
                $result[$locationIdInt] = null;
                continue;
            }

            $result[$locationIdInt] = max(1, (int) $normalizedValue);
        }

        return $result;
    }

    /**
     * @return array<string, int|string|null>
     */
    private function buildLocationServicePayload(Service $service): array
    {
        return [
            'name' => (string) $service->name,
            'description' => $service->description !== null ? (string) $service->description : null,
            'color' => $service->color !== null ? strtoupper((string) $service->color) : null,
        ];
    }

    private function redirectToEditService(Request $request, Service $service, string $message): RedirectResponse
    {
        $service->loadMissing(['locations:id', 'category:id,name,description']);
        $activeLocationIds = $service->locations
            ->filter(static fn ($location): bool => (bool) ($location->pivot?->is_active ?? false))
            ->pluck('id')
            ->map(static fn (int $id): string => (string) $id)
            ->values()
            ->all();
        $inputLocationIds = collect($request->input('active_location_ids', $activeLocationIds))
            ->map(static fn (mixed $id): string => (string) $id)
            ->values()
            ->all();
        $defaultDurationOverrides = $service->locations
            ->mapWithKeys(fn ($location): array => [
                (string) $location->id => $location->pivot?->duration_minutes !== null
                    ? (string) $location->pivot->duration_minutes
                    : '',
            ])
            ->all();
        $defaultPriceOverrides = $service->locations
            ->mapWithKeys(fn ($location): array => [
                (string) $location->id => $location->pivot?->price_minor !== null
                    ? number_format(((int) $location->pivot->price_minor) / 100, 2, '.', '')
                    : '',
            ])
            ->all();
        $defaultSortOrderOverrides = $service->locations
            ->mapWithKeys(fn ($location): array => [
                (string) $location->id => $location->pivot?->sort_order !== null
                    ? (string) $location->pivot->sort_order
                    : '',
            ])
            ->all();

        return redirect()
            ->route('services.index')
            ->withErrors(['service_edit' => $message])
            ->withInput([
                'form_scope' => 'edit',
                'modal_service_id' => $service->id,
                'name' => (string) $request->input('name', $service->name),
                'duration_minutes' => (string) $request->input('duration_minutes', $service->duration_minutes),
                'price_kr' => (string) $request->input('price_kr', $this->formatPriceMinorAsKr($service->price_minor)),
                'is_online_bookable' => (string) ($request->input('is_online_bookable', $service->is_online_bookable ? '1' : '0')),
                'requires_staff_selection' => (string) ($request->input('requires_staff_selection', $service->requiresStaffSelection() ? '1' : '0')),
                'service_category_id' => (string) $request->input('service_category_id', (string) ($service->service_category_id ?? '')),
                'sort_order' => (string) $request->input('sort_order', (string) ($service->sort_order ?? 0)),
                'buffer_before_minutes' => (string) $request->input('buffer_before_minutes', (string) ($service->buffer_before_minutes ?? 0)),
                'buffer_after_minutes' => (string) $request->input('buffer_after_minutes', (string) ($service->buffer_after_minutes ?? 0)),
                'min_notice_minutes' => (string) $request->input('min_notice_minutes', (string) ($service->min_notice_minutes ?? 0)),
                'max_advance_days' => (string) $request->input('max_advance_days', $service->max_advance_days !== null ? (string) $service->max_advance_days : ''),
                'cancellation_notice_hours' => (string) $request->input('cancellation_notice_hours', (string) ($service->cancellation_notice_hours ?? 24)),
                'color' => (string) $request->input('color', $service->color),
                'description' => (string) $request->input('description', $service->description),
                'has_location_selection' => '1',
                'active_location_ids' => $inputLocationIds,
                'location_duration_minutes' => $request->input('location_duration_minutes', $defaultDurationOverrides),
                'location_price_kr' => $request->input('location_price_kr', $defaultPriceOverrides),
                'location_sort_order' => $request->input('location_sort_order', $defaultSortOrderOverrides),
            ]);
    }

    private function resolveDefaultServiceCategoryId(int $tenantId): int
    {
        $category = ServiceCategory::query()->firstOrCreate(
            [
                'tenant_id' => $tenantId,
                'name' => 'Standard',
            ],
            [
                'description' => null,
                'sort_order' => 0,
                'is_active' => true,
            ]
        );

        return (int) $category->id;
    }

    private function reorderGlobalServices(int $tenantId, int $focusServiceId, int $requestedSortOrder): void
    {
        $orderedServiceIds = Service::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->pluck('id')
            ->map(static fn (int $id): int => $id)
            ->all();

        $orderedServiceIds = array_values(array_filter(
            $orderedServiceIds,
            static fn (int $id): bool => $id !== $focusServiceId
        ));

        $insertIndex = max(0, min($requestedSortOrder - 1, count($orderedServiceIds)));
        array_splice($orderedServiceIds, $insertIndex, 0, [$focusServiceId]);

        $now = now();

        foreach ($orderedServiceIds as $index => $serviceId) {
            DB::table('services')
                ->where('id', $serviceId)
                ->where('tenant_id', $tenantId)
                ->update([
                    'sort_order' => $index + 1,
                    'updated_at' => $now,
                ]);
        }
    }

    /**
     * @param list<int> $allowedLocationIds
     */
    private function applyRequestedLocalSortOrders(
        Request $request,
        int $tenantId,
        Service $focusService,
        array $allowedLocationIds
    ): void {
        $rawSortOrders = $request->input('location_sort_order', []);

        if (! is_array($rawSortOrders)) {
            return;
        }

        foreach ($rawSortOrders as $locationId => $value) {
            $locationIdInt = (int) $locationId;

            if (! in_array($locationIdInt, $allowedLocationIds, true)) {
                continue;
            }

            $normalizedValue = trim((string) $value);

            if ($normalizedValue === '') {
                continue;
            }

            $isActiveAtLocation = $focusService->locations()
                ->where('locations.id', $locationIdInt)
                ->wherePivot('is_active', true)
                ->exists();

            if (! $isActiveAtLocation) {
                continue;
            }

            $this->reorderLocationServices(
                $tenantId,
                $locationIdInt,
                (int) $focusService->id,
                max(1, (int) $normalizedValue)
            );
        }
    }

    private function reorderLocationServices(
        int $tenantId,
        int $locationId,
        int $focusServiceId,
        int $requestedSortOrder
    ): void {
        $orderedServiceIds = Service::queryForLocation($tenantId, $locationId)
            ->where('location_settings.is_active', true)
            ->orderByRaw('COALESCE(location_settings.sort_order, services.sort_order)')
            ->orderBy('services.name')
            ->get(['services.id'])
            ->pluck('id')
            ->map(static fn (int $id): int => $id)
            ->all();

        if (! in_array($focusServiceId, $orderedServiceIds, true)) {
            return;
        }

        $orderedServiceIds = array_values(array_filter(
            $orderedServiceIds,
            static fn (int $id): bool => $id !== $focusServiceId
        ));

        $insertIndex = max(0, min($requestedSortOrder - 1, count($orderedServiceIds)));
        array_splice($orderedServiceIds, $insertIndex, 0, [$focusServiceId]);

        $now = now();

        foreach ($orderedServiceIds as $index => $serviceId) {
            DB::table('location_service')
                ->where('location_id', $locationId)
                ->where('service_id', $serviceId)
                ->update([
                    'sort_order' => $index + 1,
                    'updated_at' => $now,
                ]);
        }
    }

    private function normalizeHexColor(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = strtoupper(trim($value));

        if (! preg_match('/^#[A-F0-9]{6}$/', $trimmed)) {
            return null;
        }

        return $trimmed;
    }

    private function parsePriceKrToMinor(mixed $rawValue, string $field = 'price_kr'): ?int
    {
        if ($rawValue === null) {
            return null;
        }

        $value = trim((string) $rawValue);

        if ($value === '') {
            return null;
        }

        $normalized = str_replace(',', '.', $value);

        if (! preg_match('/^\d+(?:\.\d{1,2})?$/', $normalized)) {
            throw ValidationException::withMessages([
                $field => 'Pris skal være et gyldigt beløb, fx 499 eller 499,95.',
            ]);
        }

        $priceMinor = (int) round(((float) $normalized) * 100);

        if ($priceMinor < 0) {
            throw ValidationException::withMessages([
                $field => 'Pris kan ikke være negativ.',
            ]);
        }

        return $priceMinor;
    }

    private function formatPriceMinorAsKr(?int $priceMinor): ?string
    {
        if ($priceMinor === null) {
            return null;
        }

        return number_format($priceMinor / 100, 2, '.', '');
    }
}
