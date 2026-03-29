<?php

namespace App\Http\Controllers\Platform;

use App\Enums\TenantRole;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Service;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use App\Models\User;
use App\Support\UploadsStorage;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class PlatformTenantController extends Controller
{
    public function show(Request $request, Tenant $tenant): View
    {
        $platformUser = $request->user('platform');
        abort_unless($platformUser?->isDeveloper(), 403);

        $tenant->loadCount(['users', 'locations', 'services', 'bookings']);
        $plans = SubscriptionPlan::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'requires_powered_by']);

        return view('platform.tenant', [
            'platformUser' => $platformUser,
            'tenant' => $tenant,
            'tenantUsers' => User::query()
                ->where('tenant_id', $tenant->id)
                ->orderByRaw(
                    'case role when ? then 0 when ? then 1 when ? then 2 when ? then 3 else 4 end',
                    [
                        TenantRole::OWNER->value,
                        TenantRole::LOCATION_MANAGER->value,
                        TenantRole::MANAGER->value,
                        TenantRole::STAFF->value,
                    ]
                )
                ->orderBy('name')
                ->get(['id', 'name', 'email', 'role', 'is_bookable', 'is_active', 'created_at']),
            'locations' => Location::query()
                ->where('tenant_id', $tenant->id)
                ->withCount(['users', 'bookings', 'services'])
                ->orderBy('name')
                ->get(),
            'plans' => $plans,
            'selectedPublicLogoPreviewUrl' => $this->resolvePublicLogoPreviewUrl($tenant->public_logo_path, (int) $tenant->id),
        ]);
    }

    public function update(Request $request, Tenant $tenant): RedirectResponse
    {
        $platformUser = $request->user('platform');
        abort_unless($platformUser?->isDeveloper(), 403);

        $payload = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required',
                'string',
                'max:255',
                'regex:/^[a-z0-9-]+$/',
                Rule::unique('tenants', 'slug')->ignore($tenant->id),
            ],
            'timezone' => ['required', 'timezone'],
            'plan_id' => ['required', Rule::exists('subscription_plans', 'id')->where(
                fn ($query) => $query->where('is_active', true)
            )],
            'public_brand_name' => ['nullable', 'string', 'max:120'],
            'public_logo_alt' => ['nullable', 'string', 'max:120'],
            'public_primary_color' => ['nullable', 'string', 'regex:/^#[A-Fa-f0-9]{6}$/'],
            'public_accent_color' => ['nullable', 'string', 'regex:/^#[A-Fa-f0-9]{6}$/'],
            'show_powered_by' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $plan = SubscriptionPlan::query()
            ->where('is_active', true)
            ->findOrFail((int) $payload['plan_id']);

        $showPoweredBy = $plan->requires_powered_by
            ? true
            : (bool) ($payload['show_powered_by'] ?? false);

        $tenant->update([
            'name' => trim($payload['name']),
            'slug' => trim($payload['slug']),
            'timezone' => (string) $payload['timezone'],
            'plan_id' => (int) $plan->id,
            'public_brand_name' => $this->nullableTrim($payload['public_brand_name'] ?? null),
            'public_logo_alt' => $this->nullableTrim($payload['public_logo_alt'] ?? null),
            'public_primary_color' => $this->normalizeHexColor($payload['public_primary_color'] ?? null),
            'public_accent_color' => $this->normalizeHexColor($payload['public_accent_color'] ?? null),
            'show_powered_by' => $showPoweredBy,
            'is_active' => (bool) ($payload['is_active'] ?? false),
        ]);

        return redirect()
            ->route('platform.tenants.show', $tenant)
            ->with('status', 'Virksomhedsoplysninger er opdateret.');
    }

    public function storeOwner(Request $request, Tenant $tenant): RedirectResponse
    {
        $platformUser = $request->user('platform');
        abort_unless($platformUser?->isDeveloper(), 403);

        $payload = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'confirmed', Password::defaults()],
            'initials' => ['nullable', 'string', 'max:6', 'regex:/^[A-Za-z0-9]{1,6}$/'],
            'is_bookable' => ['nullable', 'boolean'],
        ]);

        $user = User::query()->create([
            'tenant_id' => $tenant->id,
            'name' => trim($payload['name']),
            'email' => mb_strtolower(trim($payload['email'])),
            'password' => (string) $payload['password'],
            'role' => TenantRole::OWNER->value,
            'initials' => $this->resolveInitials((string) $payload['name'], $payload['initials'] ?? null),
            'is_bookable' => (bool) ($payload['is_bookable'] ?? false),
            'is_active' => true,
        ]);

        if ($user->is_bookable) {
            $locationIds = Location::query()
                ->where('tenant_id', $tenant->id)
                ->where('is_active', true)
                ->pluck('id')
                ->all();

            if ($locationIds !== []) {
                $user->locations()->syncWithPivotValues(
                    $locationIds,
                    ['is_active' => true],
                    false
                );
            }
        }

        return redirect()
            ->route('platform.tenants.show', $tenant)
            ->with('status', 'Ejer-brugeren er oprettet.');
    }

    public function storeLocation(Request $request, Tenant $tenant): RedirectResponse
    {
        $platformUser = $request->user('platform');
        abort_unless($platformUser?->isDeveloper(), 403);

        $payload = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'nullable',
                'string',
                'max:255',
                'regex:/^[a-z0-9-]+$/',
                Rule::unique('locations', 'slug')->where(
                    fn ($query) => $query->where('tenant_id', $tenant->id)
                ),
            ],
            'timezone' => ['required', 'timezone'],
            'address_line_1' => ['nullable', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'city' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $slug = $this->resolveLocationSlug($tenant, (string) $payload['name'], $payload['slug'] ?? null);

        $location = Location::query()->create([
            'tenant_id' => $tenant->id,
            'name' => trim($payload['name']),
            'slug' => $slug,
            'timezone' => (string) $payload['timezone'],
            'address_line_1' => $this->nullableTrim($payload['address_line_1'] ?? null),
            'address_line_2' => $this->nullableTrim($payload['address_line_2'] ?? null),
            'postal_code' => $this->nullableTrim($payload['postal_code'] ?? null),
            'city' => $this->nullableTrim($payload['city'] ?? null),
            'is_active' => (bool) ($payload['is_active'] ?? true),
        ]);

        $serviceRows = $tenant->services()
            ->select(
                'services.id',
                'services.name',
                'services.description',
                'services.duration_minutes',
                'services.price_minor',
                'services.color'
            )
            ->get()
            ->map(fn ($service): array => [
                'location_id' => $location->id,
                'service_id' => (int) $service->id,
                'name' => (string) $service->name,
                'description' => $service->description !== null ? (string) $service->description : null,
                'duration_minutes' => null,
                'color' => $service->color !== null ? strtoupper((string) $service->color) : null,
                'price_minor' => null,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ])
            ->all();

        if ($serviceRows !== []) {
            DB::table('location_service')->insertOrIgnore($serviceRows);
        }

        $bookableUserIds = $tenant->users()
            ->where('is_bookable', true)
            ->pluck('users.id')
            ->all();

        if ($bookableUserIds !== []) {
            $location->users()->syncWithPivotValues(
                $bookableUserIds,
                ['is_active' => true],
                false
            );
        }

        return redirect()
            ->route('platform.tenants.show', $tenant)
            ->with('status', 'Lokationen er oprettet.');
    }

    public function updateLocation(Request $request, Tenant $tenant, Location $location): RedirectResponse
    {
        $platformUser = $request->user('platform');
        abort_unless($platformUser?->isDeveloper(), 403);
        abort_if((int) $location->tenant_id !== (int) $tenant->id, 404);

        $payload = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required',
                'string',
                'max:255',
                'regex:/^[a-z0-9-]+$/',
                Rule::unique('locations', 'slug')
                    ->where(fn ($query) => $query->where('tenant_id', $tenant->id))
                    ->ignore($location->id),
            ],
            'timezone' => ['required', 'timezone'],
            'address_line_1' => ['nullable', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'city' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $location->update([
            'name' => trim($payload['name']),
            'slug' => trim((string) $payload['slug']),
            'timezone' => (string) $payload['timezone'],
            'address_line_1' => $this->nullableTrim($payload['address_line_1'] ?? null),
            'address_line_2' => $this->nullableTrim($payload['address_line_2'] ?? null),
            'postal_code' => $this->nullableTrim($payload['postal_code'] ?? null),
            'city' => $this->nullableTrim($payload['city'] ?? null),
            'is_active' => (bool) ($payload['is_active'] ?? false),
        ]);

        return redirect()
            ->route('platform.tenants.show', $tenant)
            ->with('status', 'Lokationen er opdateret.');
    }

    public function destroyLocation(Request $request, Tenant $tenant, Location $location): RedirectResponse
    {
        $platformUser = $request->user('platform');
        abort_unless($platformUser?->isDeveloper(), 403);
        abort_if((int) $location->tenant_id !== (int) $tenant->id, 404);

        $locationCount = Location::query()
            ->where('tenant_id', $tenant->id)
            ->count();

        if ($locationCount <= 1) {
            return redirect()
                ->route('platform.tenants.show', $tenant)
                ->withErrors(['location' => 'En virksomhed skal have mindst en lokation.']);
        }

        if ($location->bookings()->exists()) {
            return redirect()
                ->route('platform.tenants.show', $tenant)
                ->withErrors(['location' => 'Lokationen kan ikke slettes, da den har bookinger.']);
        }

        try {
            $location->delete();
        } catch (QueryException) {
            return redirect()
                ->route('platform.tenants.show', $tenant)
                ->withErrors(['location' => 'Lokationen kunne ikke slettes. Kontroller relationer og prøv igen.']);
        }

        return redirect()
            ->route('platform.tenants.show', $tenant)
            ->with('status', 'Lokationen er slettet.');
    }

    public function destroy(Request $request, Tenant $tenant): RedirectResponse
    {
        $platformUser = $request->user('platform');
        abort_unless($platformUser?->isDeveloper(), 403);

        $tenantId = (int) $tenant->id;

        if (Tenant::query()->count() <= 1) {
            return redirect()
                ->route('platform.tenants.show', $tenant)
                ->withErrors(['tenant' => 'Den sidste virksomhed kan ikke slettes.']);
        }

        $tenantName = $tenant->name;
        $filePathsToDelete = collect([
            UploadsStorage::normalizePath($tenant->public_logo_path),
            ...User::query()
                ->where('tenant_id', $tenantId)
                ->pluck('profile_photo_path')
                ->map(static fn ($path): ?string => UploadsStorage::normalizePath($path))
                ->all(),
        ])
            ->filter(static fn (?string $path): bool => is_string($path) && $path !== '')
            ->unique()
            ->values()
            ->all();

        try {
            DB::transaction(function () use ($tenantId, $tenant): void {
                Booking::query()->where('tenant_id', $tenantId)->delete();
                Customer::query()->where('tenant_id', $tenantId)->delete();
                Service::query()->where('tenant_id', $tenantId)->delete();
                User::query()->where('tenant_id', $tenantId)->delete();
                $tenant->delete();
            });
        } catch (QueryException) {
            return redirect()
                ->route('platform.tenants.show', $tenant)
                ->withErrors(['tenant' => 'Virksomheden kunne ikke slettes. Kontroller relationer og prøv igen.']);
        }

        foreach ($filePathsToDelete as $filePath) {
            UploadsStorage::delete($filePath);
        }

        return redirect()
            ->route('platform.dashboard')
            ->with('status', "Virksomheden {$tenantName} er slettet.");
    }

    private function resolveInitials(string $name, ?string $initials): string
    {
        if (filled($initials)) {
            return strtoupper(trim((string) $initials));
        }

        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $generated = collect($parts)
            ->filter()
            ->take(2)
            ->map(fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)))
            ->implode('');

        return $generated !== '' ? $generated : 'NA';
    }

    private function resolveLocationSlug(Tenant $tenant, string $name, ?string $slug): string
    {
        $base = trim((string) $slug);
        $base = $base !== '' ? $base : Str::slug($name);
        $base = $base !== '' ? $base : 'lokation';
        $candidate = $base;
        $suffix = 1;

        while (Location::query()
            ->where('tenant_id', $tenant->id)
            ->where('slug', $candidate)
            ->exists()) {
            $candidate = "{$base}-{$suffix}";
            $suffix++;
        }

        return $candidate;
    }

    private function nullableTrim(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function resolvePublicLogoPreviewUrl(?string $path, int $tenantId): ?string
    {
        $trimmed = UploadsStorage::normalizePath($path);

        if ($trimmed === null) {
            return null;
        }

        $expectedPrefix = 'tenant-branding/' . $tenantId . '/branding/';
        $legacyPublicPrefix = 'tenant-assets/' . $tenantId . '/branding/';

        if (
            ! str_starts_with($trimmed, $expectedPrefix)
            && ! str_starts_with($trimmed, $legacyPublicPrefix)
        ) {
            return null;
        }

        return UploadsStorage::url($trimmed);
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
}
