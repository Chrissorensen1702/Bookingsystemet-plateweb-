<?php

namespace App\Http\Middleware;

use App\Models\NativeAppToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateNativeAppToken
{
    /**
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $plainToken = trim((string) $request->bearerToken());

        if ($plainToken === '') {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $token = NativeAppToken::query()
            ->with('user')
            ->where('token_hash', hash('sha256', $plainToken))
            ->first();

        if (! $token || ($token->expires_at && $token->expires_at->isPast())) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $user = $token->user;

        if (! $user || ! $user->is_active) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $token->forceFill(['last_used_at' => now()])->save();

        Auth::setUser($user);
        $request->setUserResolver(static fn () => $user);
        $request->attributes->set('native_app_token', $token);

        return $next($request);
    }
}
