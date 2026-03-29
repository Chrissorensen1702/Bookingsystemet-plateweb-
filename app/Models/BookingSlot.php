<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingSlot extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'location_id',
        'booking_id',
        'staff_user_id',
        'slot_start',
    ];

    protected function casts(): array
    {
        return [
            'slot_start' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function staffMember(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_user_id');
    }
}

