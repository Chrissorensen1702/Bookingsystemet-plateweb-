<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NativeAppToken extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'token_hash',
        'push_token',
        'push_platform',
        'notifications_enabled',
        'push_token_updated_at',
        'last_used_at',
        'expires_at',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'notifications_enabled' => 'boolean',
            'push_token_updated_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
