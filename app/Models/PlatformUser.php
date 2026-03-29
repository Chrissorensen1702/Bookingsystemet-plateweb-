<?php

namespace App\Models;

use App\Enums\PlatformRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class PlatformUser extends Authenticatable
{
    use HasFactory, Notifiable;

    public const ROLE_DEVELOPER = PlatformRole::DEVELOPER->value;

    protected $fillable = [
        'name',
        'email',
        'role',
        'is_active',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function roleValue(): string
    {
        return (string) $this->role;
    }

    public function roleLabel(): string
    {
        $role = PlatformRole::tryFrom($this->roleValue());

        return $role?->label() ?? ucfirst($this->roleValue());
    }

    public function isDeveloper(): bool
    {
        return $this->roleValue() === self::ROLE_DEVELOPER;
    }
}

