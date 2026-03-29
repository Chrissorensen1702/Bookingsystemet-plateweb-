<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserWorkShift extends Model
{
    use HasFactory;

    public const ROLE_SERVICE = 'service';
    public const ROLE_ADMINISTRATION = 'administration';

    protected $fillable = [
        'tenant_id',
        'location_id',
        'user_id',
        'shift_date',
        'starts_at',
        'ends_at',
        'break_starts_at',
        'break_ends_at',
        'work_role',
        'notes',
        'is_public',
        'published_at',
        'published_by_user_id',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'shift_date' => 'date',
            'is_public' => 'boolean',
            'published_at' => 'datetime',
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by_user_id');
    }

    public function isServiceRole(): bool
    {
        return $this->workRoleValue() === self::ROLE_SERVICE;
    }

    public function workRoleValue(): string
    {
        $value = trim((string) $this->work_role);

        if ($value === self::ROLE_ADMINISTRATION) {
            return self::ROLE_ADMINISTRATION;
        }

        return self::ROLE_SERVICE;
    }

    public function workRoleLabel(): string
    {
        return $this->isServiceRole() ? 'Service' : 'Administration';
    }
}
