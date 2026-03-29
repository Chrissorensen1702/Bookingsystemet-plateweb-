<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Permission extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'label',
        'description',
    ];

    public function tenantRolePermissions(): HasMany
    {
        return $this->hasMany(TenantRolePermission::class);
    }
}
