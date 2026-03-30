<?php

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

test('unverified users are redirected to verification notice', function () {
    $user = User::factory()->unverified()->create();

    $response = $this->actingAs($user)->get(route('booking-calender'));

    $response->assertRedirect(route('verification.notice'));
});

test('verification link can verify a user without an active session', function () {
    $user = User::factory()->unverified()->create();
    $verificationUrl = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        [
            'id' => $user->id,
            'hash' => sha1((string) $user->getEmailForVerification()),
        ]
    );

    $response = $this->get($verificationUrl);

    $response->assertRedirect(route('login'));
    $response->assertSessionHas('status', 'Din e-mail er bekræftet. Du kan nu logge ind.');

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

test('creating a user sends an email verification notification', function () {
    Notification::fake();

    $owner = User::factory()->create([
        'role' => User::ROLE_OWNER,
    ]);

    $payload = [
        'name' => 'Ny Medarbejder',
        'email' => 'ny.medarbejder@example.com',
        'initials' => 'NM',
        'role' => User::ROLE_STAFF,
        'competency_scope' => User::COMPETENCY_SCOPE_GLOBAL,
        'password' => 'SecurePass123!@#',
        'password_confirmation' => 'SecurePass123!@#',
    ];

    $response = $this->actingAs($owner)->post(route('users.store'), $payload);
    $createdUser = User::query()->where('email', $payload['email'])->firstOrFail();

    $response->assertRedirect(route('users.index'));
    $response->assertSessionHas('status', 'Brugeren er oprettet. Bekræftelsesmail er sendt.');
    Notification::assertSentTo($createdUser, VerifyEmail::class);
});
