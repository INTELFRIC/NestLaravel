<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class SecureHeaders
{
    private const CSP = "default-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'";

    /**
     * The Scramble docs UI (Stoplight Elements, or Scalar when configured) loads its bundle from a CDN and
     * uses inline scripts/styles. Only that HTML page gets this policy; the API itself keeps the strict one.
     */
    private const DOCS_CSP = "default-src 'self'; "
        ."script-src 'self' 'unsafe-inline' https://unpkg.com https://cdn.jsdelivr.net; "
        ."style-src 'self' 'unsafe-inline' https://unpkg.com https://cdn.jsdelivr.net https://fonts.googleapis.com; "
        ."font-src 'self' data: https://unpkg.com https://cdn.jsdelivr.net https://fonts.gstatic.com; "
        ."img-src 'self' data: https:; "
        ."connect-src 'self' https://proxy.scalar.com; "
        ."worker-src 'self' blob:; "
        ."frame-ancestors 'none'; base-uri 'self'; form-action 'self'";

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-XSS-Protection', '0');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'geolocation=(), microphone=(), camera=()');
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $response->headers->set('Cross-Origin-Resource-Policy', 'same-origin');

        if (! $response->headers->has('Content-Security-Policy')) {
            $response->headers->set(
                'Content-Security-Policy',
                $request->routeIs('scramble.docs.ui') ? self::DOCS_CSP : self::CSP,
            );
        }

        return $response;
    }
}
