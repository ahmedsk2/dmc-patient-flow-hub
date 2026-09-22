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
 * style-src keeps the inline-style allowance: the app moved off ApexCharts to Chart.js long ago
 * (resources/js/lib/chartjs.js), but 'unsafe-inline' is still load-bearing for reasons that have
 * nothing to do with the charting library — checked one by one while auditing this (2026-09):
 *   - Inertia's own router (progress option in resources/js/app.js, plus its error-page iframe)
 *     sets inline `style` attributes directly via the DOM API and has no CSP-nonce integration.
 *   - Vue's `:style` bindings, used throughout (progress bars in Dashboard.vue/Consultations,
 *     OccupancyTracker, ChartCanvas's wrapper height) — CSP has no nonce mechanism for style
 *     ATTRIBUTES (only for <style> elements), so there is no nonce-based alternative for these.
 *   - driver.js (the onboarding tour) positions its overlay/popover by setting dozens of inline
 *     style properties on plain DOM nodes every step.
 *   - Chart.js itself sets canvas.style.width/height directly for responsive resizing.
 * Blocking style-src would break all of the above for no script-execution benefit — script-src
 * stays locked to 'self' plus the per-request nonce regardless.
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
                .'report-uri /csp-report; '
                .'report-to csp-endpoint';

            $header = $mode === 'report' ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy';
            $response->headers->set($header, $policy);
            $response->headers->set('Reporting-Endpoints', 'csp-endpoint="/csp-report"');
        }

        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'same-origin');
        // SPC-WEB-003: deny every powerful-feature/sensor API this app never uses, on top of
        // the original three (camera/microphone/geolocation) — a compromised or malvertised
        // third-party script (there are none loaded today, but the header is defense in depth)
        // gets no path to a payment sheet, a USB/serial/Bluetooth/HID device, or FLoC-successor
        // cross-site tracking via the Topics API.
        $response->headers->set('Permissions-Policy',
            'camera=(), microphone=(), geolocation=(), payment=(), usb=(), serial=(), '
            .'bluetooth=(), hid=(), browsing-topics=()');
        // SPC-WEB-002: this app is never meant to be cross-origin-embedded or read by another
        // origin's window/tab — COOP isolates the browsing context group (blocks window.opener
        // access from a popup this app opens or that opens it) and CORP stops another origin's
        // <img>/<script>/fetch(no-cors) from loading this response at all. Neither affects a
        // same-origin download link (PDF/CSV/XLSX exports) or a same-origin navigation/fetch —
        // both are same-origin by construction (SPC-TM-011: no cross-origin API surface exists).
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $response->headers->set('Cross-Origin-Resource-Policy', 'same-origin');

        if ($request->secure()) {
            // 2026-09 prod-readiness: `preload` is the exact form the browser HSTS preload list
            // requires (one year, subdomains, preload). Note it is a one-way door once the domain
            // is SUBMITTED to the list — every subdomain must serve HTTPS forever after.
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');
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
