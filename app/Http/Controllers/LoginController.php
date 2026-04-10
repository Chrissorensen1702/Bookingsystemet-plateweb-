<?php

namespace App\Http\Controllers;

use App\Support\AuthRedirects;
use App\Support\NativeApp;
use App\Support\RouteUrls;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use LaravelWebauthn\Facades\Webauthn as WebauthnFacade;

class LoginController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        $nativeApp = NativeApp::syncSession($request);

        if (Auth::check()) {
            return redirect()->to(RouteUrls::appHome());
        }

        return view('login', [
            'nativeApp' => $nativeApp,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $nativeApp = NativeApp::syncSession($request);

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

        $remember = $nativeApp || $request->boolean('remember');

        if (! Auth::attempt([
            'email' => $credentials['email'],
            'password' => $credentials['password'],
            'is_active' => true,
        ], $remember)) {
            return back()->withErrors([
                'email' => 'Forkert e-mail eller adgangskode.',
            ])->onlyInput('email');
        }

        $request->session()->regenerate();
        NativeApp::syncSession($request);

        $response = redirect()->to(AuthRedirects::resolvePostLoginRedirect($request));

        if ($nativeApp) {
            $response->withCookie(NativeApp::lockCookie($request));
        }

        return $response;
    }

    public function destroy(Request $request): RedirectResponse
    {
        $nativeApp = NativeApp::isEnabled($request);

        Auth::logout();
        WebauthnFacade::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $response = redirect()->route('login', $nativeApp ? NativeApp::query() : []);

        if ($nativeApp) {
            $response->withCookie(NativeApp::forgetLockCookie($request));
        }

        return $response;
    }
}
