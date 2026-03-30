<?php

namespace App\Http\Middleware;

use App\Support\RouteUrls;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RestrictLoginDomainRoutes
{
    public function handle(Request $request, Closure $next): Response
    {
        $loginDomain = trim((string) config('security.auth.login_domain', ''));

        if ($loginDomain === '') {
            return $next($request);
        }

        if ($this->isLoginDomainRequest($request, $loginDomain)) {
            if ($this->isAllowedOnLoginDomain($request)) {
                return $next($request);
            }

            return redirect()->to(RouteUrls::appRequest($request), $this->redirectStatus($request));
        }

        if ($this->shouldRedirectLoginPath($request)) {
            return redirect()->to(RouteUrls::loginHome(), 302);
        }

        if ($this->shouldRedirectWebauthnPath($request)) {
            return redirect()->to(RouteUrls::loginRequest($request), $this->redirectStatus($request));
        }

        return $next($request);
    }

    private function isLoginDomainRequest(Request $request, string $loginDomain): bool
    {
        return mb_strtolower($request->getHost()) === mb_strtolower($loginDomain);
    }

    private function isAllowedOnLoginDomain(Request $request): bool
    {
        $path = '/'.ltrim($request->path(), '/');
        $prefix = trim((string) config('webauthn.prefix', 'webauthn'), '/');
        $webauthnAuthPath = '/'.$prefix.'/auth';
        $webauthnOptionsPath = $webauthnAuthPath.'/options';

        if ($request->isMethod('GET') || $request->isMethod('HEAD')) {
            return in_array($path, ['/', '/login', '/csrf-token'], true);
        }

        if ($request->isMethod('POST')) {
            return in_array($path, ['/login', $webauthnAuthPath, $webauthnOptionsPath], true);
        }

        return false;
    }

    private function shouldRedirectLoginPath(Request $request): bool
    {
        if (! ($request->isMethod('GET') || $request->isMethod('HEAD'))) {
            return false;
        }

        return '/'.ltrim($request->path(), '/') === '/login';
    }

    private function shouldRedirectWebauthnPath(Request $request): bool
    {
        if (! $request->isMethod('POST')) {
            return false;
        }

        $path = '/'.ltrim($request->path(), '/');
        $prefix = trim((string) config('webauthn.prefix', 'webauthn'), '/');
        $webauthnAuthPath = '/'.$prefix.'/auth';
        $webauthnOptionsPath = $webauthnAuthPath.'/options';

        return in_array($path, [$webauthnAuthPath, $webauthnOptionsPath], true);
    }

    private function redirectStatus(Request $request): int
    {
        return $request->isMethod('GET') || $request->isMethod('HEAD')
            ? 302
            : 307;
    }
}
