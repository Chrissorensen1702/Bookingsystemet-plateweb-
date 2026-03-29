<?php

namespace App\Models;

use App\Support\TenantRolePermissionManager;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::created(function (Tenant $tenant): void {
            app(TenantRolePermissionManager::class)
                ->seedDefaultsForTenant((int) $tenant->id);
        });
    }

    protected $fillable = [
        'name',
        'plan_id',
        'public_brand_name',
        'public_logo_path',
        'public_logo_alt',
        'public_primary_color',
        'public_accent_color',
        'show_powered_by',
        'require_service_categories',
        'work_shifts_enabled',
        'slug',
        'timezone',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'show_powered_by' => 'boolean',
            'require_service_categories' => 'boolean',
            'work_shifts_enabled' => 'boolean',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'plan_id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }

    public function tenantRolePermissions(): HasMany
    {
        return $this->hasMany(TenantRolePermission::class);
    }
}
