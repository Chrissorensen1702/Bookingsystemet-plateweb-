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

        if ($loginDomain === '' || ! $this->isLoginDomainRequest($request, $loginDomain)) {
            return $next($request);
        }

        if ($this->isAllowedOnLoginDomain($request)) {
            return $next($request);
        }

        return redirect()->to(RouteUrls::appRequest($request), 302);
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
            return in_array($path, ['/', '/login'], true);
        }

        if ($request->isMethod('POST')) {
            return in_array($path, ['/login', $webauthnAuthPath, $webauthnOptionsPath], true);
        }

        return false;
    }
}
