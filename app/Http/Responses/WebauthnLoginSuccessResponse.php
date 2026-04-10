<?php

namespace App\Http\Responses;

use App\Support\AuthRedirects;
use App\Support\NativeApp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Response;
use LaravelWebauthn\Contracts\LoginSuccessResponse as LoginSuccessResponseContract;

class WebauthnLoginSuccessResponse implements LoginSuccessResponseContract
{
    public function toResponse($request)
    {
        $request = $request instanceof Request ? $request : request();
        $nativeApp = NativeApp::syncSession($request);
        $callback = AuthRedirects::resolvePostLoginRedirect($request);

        $response = $request->wantsJson()
            ? Response::json([
                'result' => Auth::check(),
                'callback' => $callback,
            ])
            : redirect()->to($callback);

        if ($nativeApp) {
            $response->headers->setCookie(NativeApp::lockCookie($request));
        }

        return $response;
    }
}
