<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantRolePermission extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'role',
        'permission_id',
        'is_allowed',
    ];

    protected function casts(): array
    {
        return [
            'is_allowed' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function permission(): BelongsTo
    {
        return $this->belongsTo(Permission::class);
    }
}
