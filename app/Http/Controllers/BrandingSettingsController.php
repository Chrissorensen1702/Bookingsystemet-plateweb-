<?php

namespace App\Http\Controllers;

use App\Enums\TenantRole;
use App\Models\Location;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\User;
use App\Support\RouteUrls;
use App\Support\TenantRolePermissionManager;
use App\Support\UploadsStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class BrandingSettingsController extends Controller
{
    private const OWNER_BRANDING_UPLOAD_ROOT = 'tenant-branding';
    private const SETTINGS_VIEWS = ['location', 'branding', 'permissions', 'activity'];

    public function index(Request $request): View
    {
        $tenantId = $this->resolveTenantId($request);
        abort_if($tenantId <= 0, 500, 'Ingen aktiv tenant er konfigureret.');
        $user = $request->user();
        $activeSettingsView = $this->resolveSettingsView($request);
        $canManageGlobal = $user instanceof User && $user->hasPermission('settings.global.manage');
        $canManageRolePermissions = $user instanceof User && $user->canManageRolePermissions();
        $isLocationManager = $user instanceof User && $user->isLocationManager();

        $selectedLocationId = $isLocationManager
            ? $this->resolveLockedLocationId($request, $tenantId)
            : $this->resolveLocationId($request, $tenantId);
        abort_if($selectedLocationId <= 0, 500, 'Ingen aktiv lokation er konfigureret.');

        /** @var Tenant $tenant */
        $tenant = Tenant::query()
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
        $locations = $this->locationScopeForRequest($request, $tenantId)
            ->orderBy('name')
            ->get([
                'id',
                'name',
                'address_line_1',
                'address_line_2',
                'postal_code',
                'city',
                'public_booking_intro_text',
                'public_booking_confirmation_text',
                'public_contact_phone',
                'public_contact_email',
            ]);
        /** @var ?Location $selectedLocation */
        $selectedLocation = $locations->firstWhere('id', $selectedLocationId);
        $plan = SubscriptionPlan::query()
            ->whereKey((int) $tenant->plan_id)
            ->first(['id', 'name', 'requires_powered_by']);

        $permissionDefinitions = [];
        $permissionRoleOptions = [];
        $permissionMatrix = [];

        if ($activeSettingsView === 'permissions') {
            $permissionDefinitions = $this->permissionManager()->permissionDefinitions();
            $permissionRoleOptions = $this->permissionManager()->editableRoleOptions();
            $permissionMatrix = $this->permissionManager()->matrixForTenant(
                $tenantId,
                array_keys($permissionRoleOptions)
            );
        }

        $activityUsers = collect();

        if ($activeSettingsView === 'activity' && $user instanceof User) {
            $allowedRoles = $this->allowedRoleValuesFor($user);

            $activityUsers = User::query()
                ->where('tenant_id', $tenantId)
                ->when(
                    ! $user->isOwner(),
                    static fn ($query) => $query->whereIn('role', $allowedRoles)
                )
                ->orderByRaw('LOWER(name)')
                ->get(['id', 'name', 'role', 'is_active', 'is_bookable']);
        }

        return view('settings', [
            'tenant' => $tenant,
            'locations' => $locations,
            'selectedLocation' => $selectedLocation,
            'selectedLocationId' => $selectedLocationId,
            'activeSettingsView' => $activeSettingsView,
            'canManageGlobal' => $canManageGlobal,
            'canManageRolePermissions' => $canManageRolePermissions,
            'isLocationManager' => $isLocationManager,
            'publicLogoPreviewUrl' => $this->resolvePublicLogoPreviewUrl(
                $tenant->public_logo_path,
                (int) $tenant->id
            ),
            'publicBookingPreviewUrl' => route('public-booking.legacy.preview', array_filter([
                'tenant' => (string) $tenant->slug,
                'location_id' => $selectedLocationId > 0 ? $selectedLocationId : null,
                'preview' => 1,
            ], static fn (mixed $value): bool => $value !== null && $value !== '')),
            'planName' => (string) ($plan?->name ?? ''),
            'planRequiresPoweredBy' => (bool) ($plan?->requires_powered_by ?? false),
            'permissionDefinitions' => $permissionDefinitions,
            'permissionRoleOptions' => $permissionRoleOptions,
            'permissionMatrix' => $permissionMatrix,
            'activityUsers' => $activityUsers,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $tenantId = $this->resolveTenantId($request);
        abort_if($tenantId <= 0, 500, 'Ingen aktiv tenant er konfigureret.');
        $user = $request->user();
        $isLocationManager = $user instanceof User && $user->isLocationManager();
        $lockedLocationId = $isLocationManager
            ? $this->resolveLockedLocationId($request, $tenantId)
            : 0;

        /** @var Tenant $tenant */
        $tenant = Tenant::query()->findOrFail($tenantId);
        $planRequiresPoweredBy = (bool) (SubscriptionPlan::query()
            ->whereKey((int) $tenant->plan_id)
            ->value('requires_powered_by') ?? false);

        if ((bool) $request->boolean('update_booking_intro')) {
            $introPayload = $request->validate([
                'location_id' => [
                    'required',
                    'integer',
                    Rule::exists('locations', 'id')->where(
                        fn ($query) => $query
                            ->where('tenant_id', $tenantId)
                            ->where('is_active', true)
                    ),
                ],
                'location_name' => ['required', 'string', 'max:255'],
                'location_public_booking_intro_text' => ['nullable', 'string', 'max:500'],
                'location_public_booking_confirmation_text' => ['nullable', 'string', 'max:500'],
                'location_address_line_1' => ['nullable', 'string', 'max:255'],
                'location_address_line_2' => ['nullable', 'string', 'max:255'],
                'location_postal_code' => ['nullable', 'string', 'max:20'],
                'location_city' => ['nullable', 'string', 'max:255'],
                'location_public_contact_phone' => ['nullable', 'string', 'max:50'],
                'location_public_contact_email' => ['nullable', 'email', 'max:255'],
            ]);

            $introLocationId = $lockedLocationId > 0
                ? $lockedLocationId
                : (int) ($introPayload['location_id'] ?? 0);
            abort_if(! $this->canAccessLocation($request, $tenantId, $introLocationId), 404);

            Location::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($introLocationId)
                ->update([
                    'name' => trim((string) $introPayload['location_name']),
                    'public_booking_intro_text' => $this->nullableTrim($introPayload['location_public_booking_intro_text'] ?? null),
                    'public_booking_confirmation_text' => $this->nullableTrim($introPayload['location_public_booking_confirmation_text'] ?? null),
                    'address_line_1' => $this->nullableTrim($introPayload['location_address_line_1'] ?? null),
                    'address_line_2' => $this->nullableTrim($introPayload['location_address_line_2'] ?? null),
                    'postal_code' => $this->nullableTrim($introPayload['location_postal_code'] ?? null),
                    'city' => $this->nullableTrim($introPayload['location_city'] ?? null),
                    'public_contact_phone' => $this->nullableTrim($introPayload['location_public_contact_phone'] ?? null),
                    'public_contact_email' => $this->nullableTrim($introPayload['location_public_contact_email'] ?? null),
                ]);

            return redirect()
                ->route('settings.index', $this->settingsRedirectParameters(
                    $request,
                    $introLocationId,
                    'location'
                ))
                ->with('status', 'Lokationsindstillinger er opdateret.');
        }

        $canManageGlobal = $user instanceof User && $user->hasPermission('settings.global.manage');
        $redirectLocationId = $lockedLocationId > 0
            ? $lockedLocationId
            : $this->resolveLocationId($request, $tenantId);

        if (! $canManageGlobal) {
            return redirect()
                ->route('settings.index', $this->settingsRedirectParameters(
                    $request,
                    $redirectLocationId,
                    'branding'
                ))
                ->withErrors(['settings' => 'Du har ikke adgang til at ændre globale brandingindstillinger.']);
        }

        $payload = $request->validate([
            'public_brand_name' => ['nullable', 'string', 'max:120'],
            'slug' => [
                'required',
                'string',
                'max:255',
                'regex:/^[a-z0-9-]+$/',
                Rule::unique('tenants', 'slug')->ignore($tenant->id),
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (RouteUrls::isReservedPublicSubdomain((string) $value)) {
                        $fail('Denne slug er reserveret og kan ikke bruges.');
                    }
                },
            ],
            'public_logo_alt' => ['nullable', 'string', 'max:120'],
            'public_primary_color' => ['nullable', 'string', 'regex:/^#[A-Fa-f0-9]{6}$/'],
            'public_accent_color' => ['nullable', 'string', 'regex:/^#[A-Fa-f0-9]{6}$/'],
            'show_powered_by' => ['nullable', 'boolean'],
            'require_service_categories' => ['nullable', 'boolean'],
            'work_shifts_enabled' => ['nullable', 'boolean'],
            'remove_public_logo' => ['nullable', 'boolean'],
            'reset_branding' => ['nullable', 'boolean'],
            'public_logo_file' => ['nullable', 'file', 'max:4096', 'mimes:svg,png,jpg,jpeg,webp'],
        ]);

        if ((bool) ($payload['reset_branding'] ?? false)) {
            $this->deleteManagedLogoFile($tenant->public_logo_path, (int) $tenant->id);

            $tenant->update([
                'public_brand_name' => null,
                'public_logo_path' => null,
                'public_logo_alt' => null,
                'public_primary_color' => null,
                'public_accent_color' => null,
                'show_powered_by' => $planRequiresPoweredBy,
            ]);

            return redirect()
                ->route('settings.index', $this->settingsRedirectParameters(
                    $request,
                    $redirectLocationId,
                    'branding'
                ))
                ->with('status', 'Branding er nulstillet. Virksomheden vises uden eget logo, indtil et nyt uploades.');
        }

        $publicLogoPath = UploadsStorage::normalizePath($tenant->public_logo_path);

        if ((bool) ($payload['remove_public_logo'] ?? false)) {
            $this->deleteManagedLogoFile($publicLogoPath, (int) $tenant->id);
            $publicLogoPath = null;
        }

        if ($request->hasFile('public_logo_file')) {
            $newLogoPath = $this->storeTenantLogo($request->file('public_logo_file'), (int) $tenant->id);

            if ($newLogoPath !== null) {
                $this->deleteManagedLogoFile($publicLogoPath, (int) $tenant->id, $newLogoPath);
                $publicLogoPath = $newLogoPath;
            }
        }

        $tenant->update([
            'public_brand_name' => $this->nullableTrim($payload['public_brand_name'] ?? null),
            'slug' => trim((string) $payload['slug']),
            'public_logo_path' => $publicLogoPath,
            'public_logo_alt' => $this->nullableTrim($payload['public_logo_alt'] ?? null),
            'public_primary_color' => $this->normalizeHexColor($payload['public_primary_color'] ?? null),
            'public_accent_color' => $this->normalizeHexColor($payload['public_accent_color'] ?? null),
            'show_powered_by' => $planRequiresPoweredBy
                ? true
                : (bool) ($payload['show_powered_by'] ?? false),
            'require_service_categories' => (bool) ($payload['require_service_categories'] ?? true),
            'work_shifts_enabled' => (bool) ($payload['work_shifts_enabled'] ?? true),
        ]);

        return redirect()
            ->route('settings.index', $this->settingsRedirectParameters(
                $request,
                $redirectLocationId,
                'branding'
            ))
            ->with('status', 'Branding er opdateret.');
    }

    private function resolveSettingsView(Request $request, string $fallback = 'location'): string
    {
        $requestedView = trim((string) $request->query('settings_view', $request->input('settings_view', $fallback)));

        return in_array($requestedView, self::SETTINGS_VIEWS, true)
            ? $requestedView
            : $fallback;
    }

    /**
     * @return array{location_id: int, settings_view: string}
     */
    private function settingsRedirectParameters(Request $request, int $locationId, string $fallbackView): array
    {
        return [
            'location_id' => $locationId,
            'settings_view' => $this->resolveSettingsView($request, $fallbackView),
        ];
    }

    private function resolveLockedLocationId(Request $request, int $tenantId): int
    {
        $lockedLocationId = (int) ($this->locationScopeForRequest($request, $tenantId)
            ->orderBy('name')
            ->value('id') ?? 0);

        abort_if($lockedLocationId <= 0, 403, 'Lokationschef har ingen aktiv lokation tildelt.');

        return $lockedLocationId;
    }

    private function nullableTrim(?string $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== ''
            ? $trimmed
            : null;
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

    private function resolvePublicLogoPreviewUrl(?string $path, int $tenantId): ?string
    {
        $normalizedPath = UploadsStorage::normalizePath($path);

        if ($normalizedPath === null) {
            return null;
        }

        $expectedPrefix = self::OWNER_BRANDING_UPLOAD_ROOT . '/' . $tenantId . '/branding/';
        $legacyPrefix = 'tenant-assets/' . $tenantId . '/branding/';

        if (
            ! str_starts_with($normalizedPath, $expectedPrefix)
            && ! str_starts_with($normalizedPath, $legacyPrefix)
        ) {
            return null;
        }

        return UploadsStorage::url($normalizedPath);
    }

    private function storeTenantLogo(?UploadedFile $file, int $tenantId): ?string
    {
        if (! $file instanceof UploadedFile) {
            return null;
        }

        $extension = strtolower((string) ($file->getClientOriginalExtension() ?: $file->extension()));

        if ($extension === '') {
            return null;
        }

        if ($extension === 'jpeg') {
            $extension = 'jpg';
        }

        $directoryRelative = self::OWNER_BRANDING_UPLOAD_ROOT . '/' . $tenantId . '/branding';

        foreach (UploadsStorage::files($directoryRelative) as $existingFile) {
            if (preg_match('/\/logo\.[A-Za-z0-9]+$/', $existingFile) === 1) {
                UploadsStorage::delete($existingFile);
            }
        }

        $filename = 'logo.' . $extension;
        $storedPath = UploadsStorage::putFileAs($directoryRelative, $file, $filename);

        if (! is_string($storedPath) || $storedPath === '') {
            return null;
        }

        return $storedPath;
    }

    private function deleteManagedLogoFile(?string $path, int $tenantId, ?string $excludePath = null): void
    {
        $normalizedPath = UploadsStorage::normalizePath($path);

        if ($normalizedPath === null) {
            return;
        }

        $expectedPrefix = self::OWNER_BRANDING_UPLOAD_ROOT . '/' . $tenantId . '/branding/';
        $legacyPrefix = 'tenant-assets/' . $tenantId . '/branding/';

        if (
            ! str_starts_with($normalizedPath, $expectedPrefix)
            && ! str_starts_with($normalizedPath, $legacyPrefix)
        ) {
            return;
        }

        $normalizedExcludePath = UploadsStorage::normalizePath($excludePath);

        if ($normalizedExcludePath !== null && $normalizedPath === $normalizedExcludePath) {
            return;
        }

        UploadsStorage::delete($normalizedPath);
    }

    /**
     * @return list<string>
     */
    private function allowedRoleValuesFor(User $actor): array
    {
        $configured = config('tenant_permissions.user_management.manageable_roles.' . $actor->roleValue(), []);

        if (is_array($configured)) {
            $validRoles = array_flip(TenantRole::values());
            $resolved = collect($configured)
                ->map(static fn (mixed $role): string => trim((string) $role))
                ->filter(static fn (string $role): bool => $role !== '' && isset($validRoles[$role]))
                ->values()
                ->all();

            if ($resolved !== []) {
                return $resolved;
            }
        }

        return match ($actor->roleValue()) {
            User::ROLE_OWNER => TenantRole::values(),
            User::ROLE_LOCATION_MANAGER => [User::ROLE_MANAGER, User::ROLE_STAFF],
            User::ROLE_MANAGER => [User::ROLE_STAFF],
            default => [],
        };
    }

    private function permissionManager(): TenantRolePermissionManager
    {
        return app(TenantRolePermissionManager::class);
    }
}
