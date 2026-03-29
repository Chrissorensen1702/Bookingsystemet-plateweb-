<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function show(): View
    {
        return view('login');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge([
            'email' => $request->string('email')->trim()->lower()->value(),
        ]);

        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['nullable', 'boolean'],
        ]);

        $remember = (bool) ($credentials['remember'] ?? false);

        if (! Auth::attempt([
            'email' => $credentials['email'],
            'password' => $credentials['password'],
            'is_active' => true,
        ], $remember)) {
            // Allow platform developers to use the same login form and be routed correctly.
            if (Auth::guard('platform')->attempt([
                'email' => $credentials['email'],
                'password' => $credentials['password'],
                'is_active' => true,
            ], $remember)) {
                $request->session()->regenerate();

                return redirect()->intended(route('platform.dashboard'));
            }

            return back()->withErrors([
                'email' => 'Forkert e-mail eller adgangskode.',
            ])->onlyInput('email');
        }

        $request->session()->regenerate();

        return redirect()->intended(route('booking-calender'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
