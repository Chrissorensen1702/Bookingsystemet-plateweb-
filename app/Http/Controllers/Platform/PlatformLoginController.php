<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class PlatformLoginController extends Controller
{
    public function show(): View|RedirectResponse
    {
        if (Auth::guard('platform')->check()) {
            return redirect()->route('platform.dashboard');
        }

        return view('platform.login');
    }

    public function store(Request $request): RedirectResponse
    {
        if (Auth::guard('platform')->check()) {
            return redirect()->route('platform.dashboard');
        }

        $request->merge([
            'email' => $request->string('email')->trim()->lower()->value(),
        ]);

        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::guard('platform')->attempt([
            'email' => $credentials['email'],
            'password' => $credentials['password'],
            'is_active' => true,
        ], false)) {
            return back()->withErrors([
                'email' => 'Forkert platform-login eller bruger er ikke aktiv.',
            ])->onlyInput('email');
        }

        $request->session()->regenerate();

        // Always go to platform dashboard after successful platform auth.
        // "intended" can contain stale URLs from the web guard flow.
        return redirect()->route('platform.dashboard');
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('platform')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('platform.login');
    }
}
