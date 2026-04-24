<?php

namespace App\Http\Controllers\Api\Native;

use App\Http\Controllers\Controller;
use App\Models\NativeAppToken;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:80'],
        ]);

        $email = mb_strtolower(trim((string) $credentials['email']));

        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->first();

        if (! $user || ! $user->is_active || ! Hash::check((string) $credentials['password'], (string) $user->password)) {
            return response()->json([
                'message' => 'Forkert e-mail eller adgangskode.',
            ], 422);
        }

        $plainToken = Str::random(80);

        $token = NativeAppToken::query()->create([
            'user_id' => $user->id,
            'name' => trim((string) ($credentials['device_name'] ?? 'iPhone')) ?: 'iPhone',
            'token_hash' => hash('sha256', $plainToken),
            'last_used_at' => now(),
            'expires_at' => now()->addDays(180),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'token' => $plainToken,
            'expires_at' => $token->expires_at?->toIso8601String(),
            'user' => $this->userPayload($user),
            'tenant' => $this->tenantPayload($user),
        ]);
    }

    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'user' => $this->userPayload($user),
            'tenant' => $this->tenantPayload($user),
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $token = $request->attributes->get('native_app_token');

        if ($token instanceof NativeAppToken) {
            $token->delete();
        }

        return response()->json(['message' => 'Logget ud.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function userPayload(User $user): array
    {
        return [
            'id' => (int) $user->id,
            'name' => (string) $user->name,
            'email' => (string) $user->email,
            'phone' => $user->phone,
            'initials' => $user->bookingInitials(),
            'role' => $user->roleValue(),
            'role_label' => $user->roleLabel(),
            'profile_photo_url' => $user->profilePhotoUrl(),
            'permissions' => [
                'bookings_manage' => $user->hasPermission('bookings.manage'),
                'services_manage' => $user->hasPermission('services.manage'),
                'availability_manage' => $user->hasPermission('availability.manage'),
                'users_manage' => $user->hasPermission('users.manage'),
                'settings_manage' => $user->hasAnyPermission([
                    'settings.location.manage',
                    'settings.global.manage',
                ]),
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function tenantPayload(User $user): ?array
    {
        $tenant = $user->tenant instanceof Tenant
            ? $user->tenant
            : $user->tenant()->first();

        if (! $tenant) {
            return null;
        }

        return [
            'id' => (int) $tenant->id,
            'name' => (string) $tenant->name,
            'slug' => (string) $tenant->slug,
            'timezone' => (string) $tenant->timezone,
        ];
    }
}
