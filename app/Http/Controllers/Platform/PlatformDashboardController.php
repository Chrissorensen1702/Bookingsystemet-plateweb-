<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\SubscriptionPlan;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PlatformDashboardController extends Controller
{
    public function index(Request $request): View
    {
        $platformUser = $request->user('platform');
        abort_unless($platformUser?->isDeveloper(), 403);

        $tenants = Tenant::query()
            ->with(['plan:id,code,name,requires_powered_by'])
            ->withCount(['users', 'locations', 'services', 'bookings'])
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'timezone', 'plan_id', 'is_active', 'created_at']);

        $plans = SubscriptionPlan::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'requires_powered_by']);

        return view('platform.dashboard', [
            'platformUser' => $platformUser,
            'tenants' => $tenants,
            'plans' => $plans,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $platformUser = $request->user('platform');
        abort_unless($platformUser?->isDeveloper(), 403);

        $payload = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'regex:/^[a-z0-9-]+$/', Rule::unique('tenants', 'slug')],
            'timezone' => ['required', 'timezone'],
            'plan_id' => ['required', Rule::exists('subscription_plans', 'id')->where(
                fn ($query) => $query->where('is_active', true)
            )],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $name = trim($payload['name']);
        $slug = $this->resolveTenantSlug($name, $payload['slug'] ?? null);
        $plan = SubscriptionPlan::query()
            ->where('is_active', true)
            ->findOrFail((int) $payload['plan_id']);

        $tenant = Tenant::query()->create([
            'name' => $name,
            'slug' => $slug,
            'timezone' => (string) $payload['timezone'],
            'plan_id' => (int) $plan->id,
            'show_powered_by' => (bool) $plan->requires_powered_by,
            'is_active' => (bool) ($payload['is_active'] ?? true),
        ]);

        Location::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Hovedafdeling',
            'slug' => 'hovedafdeling',
            'timezone' => $tenant->timezone,
            'is_active' => true,
        ]);

        return redirect()
            ->route('platform.tenants.show', $tenant)
            ->with('status', 'Virksomheden er oprettet. Du kan nu konfigurere butik og ejer.');
    }

    private function resolveTenantSlug(string $name, ?string $slug): string
    {
        $base = trim((string) $slug);
        $base = $base !== '' ? $base : Str::slug($name);
        $base = $base !== '' ? $base : 'tenant';
        $candidate = $base;
        $suffix = 1;

        while (Tenant::query()->where('slug', $candidate)->exists()) {
            $candidate = "{$base}-{$suffix}";
            $suffix++;
        }

        return $candidate;
    }
}
