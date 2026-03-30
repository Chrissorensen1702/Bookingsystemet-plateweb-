<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\TenantRole;
use App\Support\TenantPermissionRegistry;
use App\Support\TenantRolePermissionManager;
use App\Support\UploadsStorage;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use LaravelWebauthn\WebauthnAuthenticatable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, WebauthnAuthenticatable;

    /**
     * @var array<string, bool>|null
     */
    private ?array $resolvedPermissionMap = null;

    public const ROLE_OWNER = TenantRole::OWNER->value;
    public const ROLE_LOCATION_MANAGER = TenantRole::LOCATION_MANAGER->value;
    public const ROLE_MANAGER = TenantRole::MANAGER->value;
    public const ROLE_STAFF = TenantRole::STAFF->value;
    public const COMPETENCY_SCOPE_GLOBAL = 'global';
    public const COMPETENCY_SCOPE_LOCATION = 'location';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'name',
        'initials',
        'email',
        'phone',
        'role',
        'is_bookable',
        'competency_scope',
        'is_active',
        'profile_photo_path',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_bookable' => 'boolean',
            'is_active' => 'boolean',
            'role' => TenantRole::class,
        ];
    }

    public function scopeBookable(Builder $query): void
    {
        $query->where('is_bookable', true)
            ->where('is_active', true);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function locations(): BelongsToMany
    {
        return $this->belongsToMany(Location::class, 'location_user')
            ->withPivot(['is_active'])
            ->withTimestamps();
    }

    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'service_user')
            ->withTimestamps();
    }

    public function workShifts(): HasMany
    {
        return $this->hasMany(UserWorkShift::class);
    }

    public function createdWorkShifts(): HasMany
    {
        return $this->hasMany(UserWorkShift::class, 'created_by_user_id');
    }

    public function competencyScopeValue(): string
    {
        $value = trim((string) $this->competency_scope);

        if ($value === self::COMPETENCY_SCOPE_LOCATION) {
            return self::COMPETENCY_SCOPE_LOCATION;
        }

        return self::COMPETENCY_SCOPE_GLOBAL;
    }

    public function usesLocationCompetencies(): bool
    {
        return $this->competencyScopeValue() === self::COMPETENCY_SCOPE_LOCATION;
    }

    public function competencyScopeLabel(): string
    {
        return $this->usesLocationCompetencies()
            ? 'Lokationsspecifik'
            : 'Samme på alle lokationer';
    }

    public function isOwner(): bool
    {
        return $this->roleValue() === self::ROLE_OWNER;
    }

    public function isLocationManager(): bool
    {
        return $this->roleValue() === self::ROLE_LOCATION_MANAGER;
    }

    public function isManager(): bool
    {
        return $this->roleValue() === self::ROLE_MANAGER;
    }

    public function isStaff(): bool
    {
        return $this->roleValue() === self::ROLE_STAFF;
    }

    public function canManageUsers(): bool
    {
        return $this->hasPermission('users.manage');
    }

    public function canManageServices(): bool
    {
        return $this->hasAnyPermission([
            'services.manage',
            'availability.manage',
            'settings.location.manage',
        ]);
    }

    public function canManageBranding(): bool
    {
        return $this->hasPermission('settings.global.manage');
    }

    public function canManageRolePermissions(): bool
    {
        return $this->hasPermission('users.permissions.manage');
    }

    public function workShiftsEnabled(): bool
    {
        $tenantId = max(0, (int) $this->tenant_id);

        if ($tenantId <= 0) {
            return true;
        }

        return (bool) (Tenant::query()
            ->whereKey($tenantId)
            ->value('work_shifts_enabled') ?? true);
    }

    public function hasPermission(string $permissionKey): bool
    {
        $key = trim($permissionKey);

        if ($key === '') {
            return false;
        }

        if ($this->isOwner()) {
            return true;
        }

        if ($this->resolvedPermissionMap === null) {
            $this->resolvedPermissionMap = app(TenantRolePermissionManager::class)
                ->permissionsForUser($this);
        }

        if (array_key_exists($key, $this->resolvedPermissionMap)) {
            return (bool) $this->resolvedPermissionMap[$key];
        }

        return TenantPermissionRegistry::defaultAllowed($this->roleValue(), $key);
    }

    /**
     * @param list<string> $permissionKeys
     */
    public function hasAnyPermission(array $permissionKeys): bool
    {
        foreach ($permissionKeys as $permissionKey) {
            if ($this->hasPermission($permissionKey)) {
                return true;
            }
        }

        return false;
    }

    public function flushResolvedPermissions(): void
    {
        $this->resolvedPermissionMap = null;
    }

    public function roleValue(): string
    {
        if ($this->role instanceof TenantRole) {
            return $this->role->value;
        }

        return (string) $this->role;
    }

    public function roleLabel(): string
    {
        $role = TenantRole::tryFrom($this->roleValue());

        return $role?->label() ?? ucfirst($this->roleValue());
    }

    public function bookingInitials(): string
    {
        if (filled($this->initials)) {
            return strtoupper(trim((string) $this->initials));
        }

        $parts = preg_split('/\s+/', trim((string) $this->name)) ?: [];
        $initials = collect($parts)
            ->filter()
            ->take(2)
            ->map(fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)))
            ->implode('');

        return $initials !== '' ? $initials : '--';
    }

    public function profilePhotoUrl(): ?string
    {
        $path = UploadsStorage::normalizePath($this->profile_photo_path);
        $tenantId = max(0, (int) $this->tenant_id);
        $userId = max(0, (int) $this->id);

        if ($path === null || $tenantId <= 0 || $userId <= 0) {
            return null;
        }

        $expectedPrefix = 'tenant-assets/' . $tenantId . '/users/' . $userId . '/profile/';

        if (! str_starts_with($path, $expectedPrefix)) {
            return null;
        }

        return UploadsStorage::url($path);
    }
}
