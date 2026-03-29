<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LocationDateOverrideSlot extends Model
{
    use HasFactory;

    protected $fillable = [
        'location_date_override_id',
        'opens_at',
        'closes_at',
    ];

    public function override(): BelongsTo
    {
        return $this->belongsTo(LocationDateOverride::class, 'location_date_override_id');
    }
}
