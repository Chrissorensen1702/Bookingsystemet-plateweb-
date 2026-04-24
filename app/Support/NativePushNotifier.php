<?php

namespace App\Support;

use App\Models\Booking;
use App\Models\NativeAppToken;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

class NativePushNotifier
{
    private const EXPO_PUSH_URL = 'https://exp.host/--/api/v2/push/send';

    public function sendBookingCreated(Booking $booking): void
    {
        $staffUserId = (int) $booking->staff_user_id;

        if ($staffUserId <= 0) {
            return;
        }

        $tokens = NativeAppToken::query()
            ->where('user_id', $staffUserId)
            ->where('notifications_enabled', true)
            ->whereNotNull('push_token')
            ->where(function ($query): void {
                $query
                    ->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->get(['id', 'push_token']);

        if ($tokens->isEmpty()) {
            return;
        }

        $booking->loadMissing([
            'customer:id,name',
            'service:id,name',
            'location:id,name',
        ]);

        $messages = $tokens
            ->map(fn (NativeAppToken $token): array => $this->bookingCreatedMessage($booking, (string) $token->push_token))
            ->values()
            ->all();

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->timeout(5)
                ->post(self::EXPO_PUSH_URL, $messages);

            $this->disableInvalidTokens($response, $tokens->pluck('push_token')->values()->all());
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function bookingCreatedMessage(Booking $booking, string $pushToken): array
    {
        $customerName = trim((string) ($booking->customer?->name ?? 'Ny kunde')) ?: 'Ny kunde';
        $serviceName = trim((string) ($booking->service?->name ?? 'Booking')) ?: 'Booking';
        $startsAt = $booking->starts_at;
        $time = $startsAt?->format('H:i') ?? '';
        $date = $startsAt?->format('Y-m-d') ?? '';
        $bodyParts = array_filter([$customerName, $serviceName, $time]);

        return [
            'to' => $pushToken,
            'sound' => 'default',
            'title' => 'Ny booking',
            'body' => implode(' · ', $bodyParts),
            'data' => [
                'type' => 'booking_created',
                'booking_id' => (string) $booking->id,
                'location_id' => (string) $booking->location_id,
                'date' => $date,
            ],
        ];
    }

    /**
     * @param array<int, string|null> $pushTokens
     */
    private function disableInvalidTokens(Response $response, array $pushTokens): void
    {
        if (! $response->successful()) {
            return;
        }

        $rows = $response->json('data');

        if (! is_array($rows)) {
            return;
        }

        foreach ($rows as $index => $row) {
            if (! is_array($row) || ($row['status'] ?? null) !== 'error') {
                continue;
            }

            if (($row['details']['error'] ?? null) !== 'DeviceNotRegistered') {
                continue;
            }

            $pushToken = $pushTokens[$index] ?? null;

            if (! is_string($pushToken) || $pushToken === '') {
                continue;
            }

            NativeAppToken::query()
                ->where('push_token', $pushToken)
                ->update([
                    'push_token' => null,
                    'notifications_enabled' => false,
                    'push_token_updated_at' => now(),
                ]);
        }
    }
}
