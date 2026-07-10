<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Prod-readiness closeout, Item 1 (docs/superpowers/specs/2026-07-10-prod-readiness-headers-ci-
 * brandsolid-design.md): response security headers on every `web` request. This app holds PHI, so
 * headers are safe-by-default — CSP enforces (not just reports) unless CSP_MODE says otherwise,
 * and authenticated pages never end up sitting in a shared-workstation disk cache.
 *
 * CSP nonce: generated once per request and shared to Blade as $cspNonce BEFORE $next() runs — the
 * root view renders deep inside the pipeline (Inertia's initial-visit HTML response), so sharing
 * it here reaches app.blade.php's one inline script (the no-flash theme bootstrap) regardless of
 * this middleware's position in the `web` group.
 *
 * style-src keeps the inline-style allowance: ApexCharts/Vue set style attributes at runtime, and
 * blocking that would break every chart for no script-execution benefit — script-src stays locked
 * to 'self' plus the per-request nonce.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = base64_encode(random_bytes(16));
        $request->attributes->set('cspNonce', $nonce);
        view()->share('cspNonce', $nonce);

        /** @var Response $response */
        $response = $next($request);

        $mode = config('security.csp_mode', 'enforce');

        // Vite dev-server (HMR) auto-relax: while `npm run dev` runs, Vite drops a `public/hot`
        // sentinel and the page loads scripts + a websocket from localhost:5173 — both violate the
        // 'self'-locked policy and an enforced CSP would hard-break local development. The sentinel
        // is gitignored and never exists on a deployed host, so this cannot weaken production.
        if (is_file(public_path('hot'))) {
            $mode = 'off';
        }

        if ($mode !== 'off') {
            $policy = "default-src 'self'; "
                ."script-src 'self' 'nonce-{$nonce}'; "
                ."style-src 'self' 'unsafe-inline'; "
                ."img-src 'self' data: blob:; "
                ."font-src 'self' data:; "
                ."connect-src 'self'; "
                ."frame-ancestors 'none'; "
                ."base-uri 'self'; "
                ."form-action 'self'; "
                ."object-src 'none'; "
                // violation telemetry → the log-only /csp-report sink. report-uri is the
                // universally-supported directive; report-to + the Reporting-Endpoints header
                // below are its successor pair — browsers use whichever they understand.
                ."report-uri /csp-report; "
                ."report-to csp-endpoint";

            $header = $mode === 'report' ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy';
            $response->headers->set($header, $policy);
            $response->headers->set('Reporting-Endpoints', 'csp-endpoint="/csp-report"');
        }

        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'same-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        if ($request->user()) {
            $cacheControl = (string) $response->headers->get('Cache-Control', '');
            if (! str_contains($cacheControl, 'no-store')) {
                $response->headers->set('Cache-Control', 'no-store, private');
            }
        }

        return $response;
    }
}
