<?php

namespace Database\Seeders;

use App\Enums\PlatformRole;
use App\Models\PlatformUser;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class PlatformUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = mb_strtolower(trim((string) env('PLATFORM_DEV_EMAIL', '')));
        $password = (string) env('PLATFORM_DEV_PASSWORD', '');
        $name = trim((string) env('PLATFORM_DEV_NAME', 'PlateWeb Developer'));

        if ($email === '' || $password === '') {
            return;
        }

        PlatformUser::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => $name !== '' ? $name : 'PlateWeb Developer',
                'role' => PlatformRole::DEVELOPER->value,
                'is_active' => true,
                'password' => Hash::make($password),
                'email_verified_at' => now(),
            ]
        );
    }
}

