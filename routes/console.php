<?php

use App\Models\Booking;
use App\Models\User;
use App\Support\BookingSmsNotifier;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
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

Artisan::command('app:test-email {to}', function () {
    $to = mb_strtolower(trim((string) $this->argument('to')));

    $validator = Validator::make([
        'to' => $to,
    ], [
        'to' => ['required', 'email'],
    ]);

    if ($validator->fails()) {
        foreach ($validator->errors()->all() as $error) {
            $this->error($error);
        }

        return 1;
    }

    try {
        Mail::raw(
            'Dette er en testmail fra ' . config('app.name') . '. Hvis du kan laese denne, virker SMTP-opsaetningen.',
            function ($message) use ($to): void {
                $message
                    ->to($to)
                    ->subject('Testmail fra ' . config('app.name'));
            }
        );
    } catch (\Throwable $exception) {
        report($exception);
        $this->error('Kunne ikke sende testmail. Tjek SMTP-opsaetning i .env.');

        return 1;
    }

    $this->info('Testmail sendt til: ' . $to);

    return 0;
})->purpose('Send a test email to verify SMTP configuration');

Artisan::command('app:test-sms {to}', function () {
    $to = trim((string) $this->argument('to'));

    $validator = Validator::make([
        'to' => $to,
    ], [
        'to' => ['required', 'string', 'max:50'],
    ]);

    if ($validator->fails()) {
        foreach ($validator->errors()->all() as $error) {
            $this->error($error);
        }

        return 1;
    }

    try {
        /** @var BookingSmsNotifier $notifier */
        $notifier = app(BookingSmsNotifier::class);
        $notifier->sendRaw(
            $to,
            'Test-SMS fra ' . config('app.name') . '. Hvis du kan laese denne, virker Twilio-opsaetningen.'
        );
    } catch (\Throwable $exception) {
        report($exception);
        $this->error('Kunne ikke sende test-SMS. Tjek Twilio-opsaetning i .env.');

        return 1;
    }

    $this->info('Test-SMS sendt til: ' . $to);

    return 0;
})->purpose('Send a test SMS through Twilio');

Schedule::command('bookings:auto-complete')
    ->everyMinute()
    ->withoutOverlapping();
