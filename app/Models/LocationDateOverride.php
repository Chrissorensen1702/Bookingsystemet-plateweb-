<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LocationDateOverride extends Model
{
    use HasFactory;

    protected $fillable = [
        'location_id',
        'override_date',
        'is_closed',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'override_date' => 'date',
            'is_closed' => 'boolean',
        ];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function slots(): HasMany
    {
        return $this->hasMany(LocationDateOverrideSlot::class, 'location_date_override_id');
    }
}
