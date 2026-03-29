<?php

namespace App\Http\Controllers;

use App\Models\Location;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

abstract class Controller
{
    protected function resolveTenantId(?Request $request = null): int
    {
        $userTenantId = (int) ($request?->user()?->tenant_id ?? 0);

        if ($userTenantId > 0) {
            return $userTenantId;
        }

        $queryTenantSlug = trim((string) ($request?->query('tenant') ?? ''));

        if ($queryTenantSlug !== '') {
            $queryTenantId = (int) Tenant::query()
                ->where('slug', $queryTenantSlug)
                ->where('is_active', true)
                ->value('id');

            if ($queryTenantId > 0) {
                return $queryTenantId;
            }
        }

        return (int) (Tenant::query()
            ->where('is_active', true)
            ->value('id') ?? 0);
    }

    protected function resolveLocationId(?Request $request, int $tenantId): int
    {
        $queryLocationId = max(0, (int) ($request?->query('location_id') ?? 0));
        $locationScope = $this->locationScopeForRequest($request, $tenantId);

        if ($queryLocationId > 0) {
            $exists = (clone $locationScope)
                ->whereKey($queryLocationId)
                ->exists();

            if ($exists) {
                return $queryLocationId;
            }
        }

        return (int) ((clone $locationScope)
            ->orderBy('name')
            ->value('id') ?? 0);
    }

    /**
     * @return Builder<Location>
     */
    protected function locationScopeForRequest(?Request $request, int $tenantId): Builder
    {
        $scope = Location::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true);

        $user = $request?->user();

        if (! $user instanceof User) {
            return $scope;
        }

        if ((int) $user->tenant_id !== $tenantId) {
            return $scope->whereRaw('1 = 0');
        }

        if ($user->isOwner()) {
            return $scope;
        }

        return $scope->whereHas('users', function (Builder $query) use ($user): void {
            $query->where('users.id', $user->id)
                ->where('location_user.is_active', true);
        });
    }

    protected function canAccessLocation(?Request $request, int $tenantId, int $locationId): bool
    {
        if ($locationId <= 0) {
            return false;
        }

        return $this->locationScopeForRequest($request, $tenantId)
            ->whereKey($locationId)
            ->exists();
    }

    /**
     * @return list<int>
     */
    protected function resolveAccessibleLocationIds(?Request $request, int $tenantId): array
    {
        return $this->locationScopeForRequest($request, $tenantId)
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (int $id): int => $id)
            ->all();
    }
}
