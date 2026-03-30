<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Location extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'name',
        'slug',
        'timezone',
        'address_line_1',
        'address_line_2',
        'postal_code',
        'city',
        'public_booking_intro_text',
        'public_booking_confirmation_text',
        'public_contact_phone',
        'public_contact_email',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'location_user')
            ->withPivot(['is_active'])
            ->withTimestamps();
    }

    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'location_service')
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

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function openingHours(): HasMany
    {
        return $this->hasMany(LocationOpeningHour::class);
    }

    public function closures(): HasMany
    {
        return $this->hasMany(LocationClosure::class);
    }

    public function dateOverrides(): HasMany
    {
        return $this->hasMany(LocationDateOverride::class);
    }
}
