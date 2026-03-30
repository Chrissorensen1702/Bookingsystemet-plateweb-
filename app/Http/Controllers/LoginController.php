<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use LaravelWebauthn\Facades\Webauthn as WebauthnFacade;

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
        ]);

        if (! Auth::attempt([
            'email' => $credentials['email'],
            'password' => $credentials['password'],
            'is_active' => true,
        ], false)) {
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
        WebauthnFacade::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
