<?php

namespace App\Enums;

enum TenantRole: string
{
    case OWNER = 'owner';
    case LOCATION_MANAGER = 'location_manager';
    case MANAGER = 'manager';
    case STAFF = 'staff';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $role): string => $role->value,
            self::cases()
        );
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $role) {
            $options[$role->value] = $role->label();
        }

        return $options;
    }

    public function label(): string
    {
        return match ($this) {
            self::OWNER => 'Ejer',
            self::LOCATION_MANAGER => 'Lokationschef',
            self::MANAGER => 'Leder',
            self::STAFF => 'Ansat',
        };
    }
}

