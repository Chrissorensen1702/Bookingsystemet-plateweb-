<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Service extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'service_category_id',
        'name',
        'duration_minutes',
        'price_minor',
        'color',
        'description',
        'is_online_bookable',
        'requires_staff_selection',
        'sort_order',
        'buffer_before_minutes',
        'buffer_after_minutes',
        'min_notice_minutes',
        'max_advance_days',
        'cancellation_notice_hours',
    ];

    protected function casts(): array
    {
        return [
            'is_online_bookable' => 'boolean',
            'requires_staff_selection' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'service_category_id');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function locations(): BelongsToMany
    {
        return $this->belongsToMany(Location::class, 'location_service')
            ->withPivot([
                'name',
                'description',
                'duration_minutes',
                'color',
                'price_minor',
                'sort_order',
                'is_active',
            ])
            ->withTimestamps();
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'service_user')
            ->withTimestamps();
    }

    /**
     * @return Builder<Service>
     */
    public static function queryForLocation(int $tenantId, int $locationId): Builder
    {
        return static::query()
            ->where('services.tenant_id', $tenantId)
            ->join('service_categories', 'service_categories.id', '=', 'services.service_category_id')
            ->join('location_service as location_settings', function ($join) use ($locationId): void {
                $join->on('location_settings.service_id', '=', 'services.id')
                    ->where('location_settings.location_id', $locationId);
            })
            ->select([
                'services.*',
                'service_categories.name as category_name',
                'service_categories.description as category_description',
                'service_categories.sort_order as category_sort_order',
                'location_settings.is_active as location_is_active',
                'location_settings.name as location_name',
                'location_settings.description as location_description',
                'location_settings.duration_minutes as location_duration_minutes',
                'location_settings.price_minor as location_price_minor',
                'location_settings.sort_order as location_sort_order',
                'location_settings.color as location_color',
            ]);
    }

    public function effectiveDurationMinutes(): int
    {
        $locationValue = $this->getAttribute('location_duration_minutes');
        $source = is_numeric($locationValue)
            ? (int) $locationValue
            : (int) $this->duration_minutes;

        return max(15, $source);
    }

    public function effectivePriceMinor(): ?int
    {
        $locationValue = $this->getAttribute('location_price_minor');

        if ($locationValue !== null && is_numeric($locationValue)) {
            return (int) $locationValue;
        }

        return $this->price_minor !== null ? (int) $this->price_minor : null;
    }

    public function bufferBeforeMinutes(): int
    {
        return max(0, (int) ($this->buffer_before_minutes ?? 0));
    }

    public function bufferAfterMinutes(): int
    {
        return max(0, (int) ($this->buffer_after_minutes ?? 0));
    }

    public function minNoticeMinutes(): int
    {
        return max(0, (int) ($this->min_notice_minutes ?? 0));
    }

    public function maxAdvanceDays(): ?int
    {
        $value = $this->max_advance_days;

        if ($value === null || $value === '') {
            return null;
        }

        $days = (int) $value;

        return $days > 0 ? $days : null;
    }

    public function cancellationNoticeHours(): int
    {
        return max(0, (int) ($this->cancellation_notice_hours ?? 0));
    }

    public function requiresStaffSelection(): bool
    {
        return (bool) ($this->requires_staff_selection ?? true);
    }

    public function getCategoryNameAttribute(?string $value): string
    {
        $attributeValue = trim((string) $value);

        if ($attributeValue !== '') {
            return $attributeValue;
        }

        if ($this->relationLoaded('category')) {
            return trim((string) ($this->category?->name ?? '')) ?: 'Standard';
        }

        $resolvedName = trim((string) $this->category()->value('name'));

        return $resolvedName !== '' ? $resolvedName : 'Standard';
    }

    public function getCategoryDescriptionAttribute(?string $value): ?string
    {
        $attributeValue = trim((string) $value);

        if ($attributeValue !== '') {
            return $attributeValue;
        }

        if ($this->relationLoaded('category')) {
            $resolved = trim((string) ($this->category?->description ?? ''));

            return $resolved !== '' ? $resolved : null;
        }

        $resolved = trim((string) $this->category()->value('description'));

        return $resolved !== '' ? $resolved : null;
    }
}
