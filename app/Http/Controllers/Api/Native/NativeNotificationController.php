<?php

namespace App\Http\Controllers\Api\Native;

use App\Http\Controllers\Controller;
use App\Models\NativeAppToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class NativeNotificationController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'push_token' => ['required', 'string', 'max:255'],
            'platform' => ['nullable', 'string', Rule::in(['ios', 'android', 'unknown'])],
        ]);

        $pushToken = trim((string) $validated['push_token']);

        if (! str_starts_with($pushToken, 'ExpoPushToken[') && ! str_starts_with($pushToken, 'ExponentPushToken[')) {
            throw ValidationException::withMessages([
                'push_token' => 'Push-token er ikke gyldig.',
            ]);
        }

        $nativeToken = $request->attributes->get('native_app_token');

        abort_unless($nativeToken instanceof NativeAppToken, 401);

        $nativeToken->forceFill([
            'push_token' => $pushToken,
            'push_platform' => $validated['platform'] ?? 'unknown',
            'notifications_enabled' => true,
            'push_token_updated_at' => now(),
        ])->save();

        return response()->json([
            'message' => 'Notifikationer er aktiveret.',
        ]);
    }
}
