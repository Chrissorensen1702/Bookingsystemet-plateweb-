<?php

use App\Models\Booking;
use App\Models\User;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('app:create-admin {--name=} {--email=} {--password=}', function () {
    $name = (string) ($this->option('name') ?: $this->ask('Navn'));
    $email = mb_strtolower(trim((string) ($this->option('email') ?: $this->ask('E-mail'))));
    $password = (string) ($this->option('password') ?: $this->secret('Adgangskode'));
    $passwordConfirmation = (string) ($this->option('password') ?: $this->secret('Bekraeft adgangskode'));

    $validator = Validator::make([
        'name' => $name,
        'email' => $email,
        'password' => $password,
        'password_confirmation' => $passwordConfirmation,
    ], [
        'name' => ['required', 'string', 'max:255'],
        'email' => ['required', 'email', 'max:255'],
        'password' => ['required', 'confirmed', Password::defaults()],
    ]);

    if ($validator->fails()) {
        foreach ($validator->errors()->all() as $error) {
            $this->error($error);
        }

        return 1;
    }

    User::query()->updateOrCreate(
        ['email' => $email],
        [
            'name' => $name,
            'role' => User::ROLE_OWNER,
            'is_active' => true,
            'password' => Hash::make($password),
        ],
    );

    $this->info('Admin-bruger oprettet/opdateret.');

    return 0;
})->purpose('Create or update an admin login without storing credentials in seeders');

Artisan::command('bookings:auto-complete', function () {
    $now = now();

    $updated = Booking::query()
        ->where('status', Booking::STATUS_CONFIRMED)
        ->where('ends_at', '<=', $now)
        ->update([
            'status' => Booking::STATUS_COMPLETED,
            'completed_at' => $now,
            'completed_by_user_id' => null,
            'updated_at' => $now,
        ]);

    $this->info("Auto-completed bookings: {$updated}");

    return 0;
})->purpose('Automatically mark confirmed bookings as completed when their end time has passed');

Schedule::command('bookings:auto-complete')
    ->everyMinute()
    ->withoutOverlapping();
