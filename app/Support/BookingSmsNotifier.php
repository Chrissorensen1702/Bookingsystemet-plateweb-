<?php

namespace App\Support;

use App\Models\Booking;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class BookingSmsNotifier
{
    public function sendConfirmation(Booking $booking): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        $booking->loadMissing([
            'customer:id,name,phone',
            'service:id,name',
            'staffMember:id,name',
            'location:id,name,timezone',
            'tenant:id,name,public_brand_name',
        ]);

        $to = $this->normalizePhone((string) ($booking->customer?->phone ?? ''));

        if ($to === null) {
            return false;
        }

        return $this->sendRaw($to, $this->buildConfirmationMessage($booking));
    }

    public function sendRaw(string $to, string $message): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        $sid = trim((string) config('services.twilio.sid'));
        $authToken = trim((string) config('services.twilio.auth_token'));

        if ($sid === '' || $authToken === '') {
            throw new RuntimeException('Twilio credentials mangler i konfigurationen.');
        }

        $recipient = $this->normalizePhone($to);

        if ($recipient === null) {
            throw new RuntimeException('Ugyldigt modtagernummer. Brug E.164-format, fx +4522334455.');
        }

        $payload = [
            'To' => $recipient,
            'Body' => trim($message),
        ];

        $messagingServiceSid = trim((string) config('services.twilio.messaging_service_sid'));
        $from = trim((string) config('services.twilio.from'));

        if ($messagingServiceSid !== '') {
            $payload['MessagingServiceSid'] = $messagingServiceSid;
        } elseif ($from !== '') {
            $payload['From'] = $from;
        } else {
            throw new RuntimeException('Twilio afsender mangler. Sæt TWILIO_MESSAGING_SERVICE_SID eller TWILIO_MESSAGING_FROM.');
        }

        $response = Http::asForm()
            ->withBasicAuth($sid, $authToken)
            ->post(
                'https://api.twilio.com/2010-04-01/Accounts/' . $sid . '/Messages.json',
                $payload
            );

        if (! $response->successful()) {
            throw new RuntimeException('Twilio SMS-fejl: HTTP ' . $response->status() . ' - ' . $response->body());
        }

        return true;
    }

    private function isEnabled(): bool
    {
        return (bool) config('services.twilio.sms_enabled', false);
    }

    private function buildConfirmationMessage(Booking $booking): string
    {
        $brandName = trim((string) ($booking->tenant?->public_brand_name ?: $booking->tenant?->name ?: config('app.name')));
        $serviceName = trim((string) ($booking->service?->name ?: 'behandling'));
        $locationName = trim((string) ($booking->location?->name ?: 'klinikken'));
        $staffName = trim((string) ($booking->staffMember?->name ?? ''));

        $startsAt = $booking->starts_at;
        $timezone = trim((string) ($booking->location?->timezone ?: config('app.timezone', 'UTC')));
        $dateText = '';
        $timeText = '';

        if ($startsAt instanceof CarbonInterface) {
            $localized = $startsAt->setTimezone($timezone !== '' ? $timezone : 'UTC');
            $dateText = $localized->format('d.m.Y');
            $timeText = $localized->format('H:i');
        }

        $pieces = [
            'Tak for din booking hos ' . $brandName . '.',
            ucfirst($serviceName) . ($dateText !== '' ? ' den ' . $dateText : '') . ($timeText !== '' ? ' kl. ' . $timeText : '') . '.',
        ];

        if ($staffName !== '') {
            $pieces[] = 'Medarbejder: ' . $staffName . '.';
        }

        if ($locationName !== '') {
            $pieces[] = 'Lokation: ' . $locationName . '.';
        }

        return implode(' ', $pieces);
    }

    private function normalizePhone(string $input): ?string
    {
        $candidate = trim($input);

        if ($candidate === '') {
            return null;
        }

        $candidate = preg_replace('/\s+/', '', $candidate) ?? '';

        if (str_starts_with($candidate, '00')) {
            $candidate = '+' . substr($candidate, 2);
        }

        if (str_starts_with($candidate, '+')) {
            $digits = preg_replace('/\D/', '', substr($candidate, 1)) ?? '';

            return $digits !== '' ? '+' . $digits : null;
        }

        $digits = preg_replace('/\D/', '', $candidate) ?? '';

        if ($digits === '') {
            return null;
        }

        $defaultCountryCode = preg_replace('/\D/', '', (string) config('services.twilio.default_country_code', '+45')) ?? '';

        if ($defaultCountryCode !== '' && str_starts_with($digits, $defaultCountryCode)) {
            return '+' . $digits;
        }

        if ($defaultCountryCode === '') {
            return null;
        }

        return '+' . $defaultCountryCode . ltrim($digits, '0');
    }
}
