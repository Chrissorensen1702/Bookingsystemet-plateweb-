<?php

namespace App\Enums;

enum PlatformRole: string
{
    case DEVELOPER = 'developer';

    public function label(): string
    {
        return match ($this) {
            self::DEVELOPER => 'Developer',
        };
    }
}

