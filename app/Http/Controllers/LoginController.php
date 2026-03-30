<?php

namespace App\Http\Controllers;

use App\Support\RouteUrls;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use LaravelWebauthn\Facades\Webauthn as WebauthnFacade;

class LoginController extends Controller
{
    public function show(): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->to(RouteUrls::appHome());
        }

        return view('login');
    }

    public function store(Request $request): RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->to(RouteUrls::appHome());
        }

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

        return redirect()->to($this->resolvePostLoginRedirect($request));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        WebauthnFacade::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    private function resolvePostLoginRedirect(Request $request): string
    {
        $fallback = RouteUrls::appHome();
        $intended = $request->session()->pull('url.intended');

        if (! is_string($intended) || trim($intended) === '') {
            return $fallback;
        }

        $intendedHost = mb_strtolower(trim((string) (parse_url($intended, PHP_URL_HOST) ?: '')));
        $intendedPath = '/'.ltrim((string) (parse_url($intended, PHP_URL_PATH) ?: '/'), '/');
        $loginHost = mb_strtolower(RouteUrls::loginHost());
        $appHost = mb_strtolower(RouteUrls::appHost());

        if ($loginHost !== '' && $intendedHost === $loginHost) {
            return $fallback;
        }

        if ($intendedPath === '/login') {
            return $fallback;
        }

        if ($intendedHost !== '' && $appHost !== '' && $intendedHost !== $appHost) {
            return $fallback;
        }

        return $intended;
    }
}
