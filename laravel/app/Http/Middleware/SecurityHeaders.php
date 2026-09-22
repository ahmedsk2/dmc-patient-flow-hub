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
 * style-src takes NO inline allowance (tightened 2026-09-22, SPC-WEB-002). Each reason the old
 * allowance cited was re-checked against the built bundle and the browser's actual rules:
 *   - Vue's `:style` bindings, Chart.js's responsive canvas sizing and driver.js's tour positioning
 *     all set style PROPERTIES through the DOM (`el.style.x = …`). CSP does not police the CSSOM —
 *     only markup `style` attributes and <style> elements — so none of them ever needed it.
 *   - Inertia inserts <style> elements at runtime (its progress bar, and the modal it renders for a
 *     non-Inertia error response such as the branded 429 page). Those DO need permission, and
 *     Inertia stamps a nonce on them when it is given one: app.blade.php publishes the per-request
 *     nonce as <meta name="csp-nonce">, resources/js/app.js passes it to createInertiaApp().
 *   - The Blade templates carry no `style` attributes at all (the PDF report templates do, but
 *     dompdf renders those server-side — no browser, no CSP).
 * Verified in a browser against a production build before shipping: every page reached, zero CSP
 * violations. If a future change needs an inline <style>, give it the nonce rather than reopening
 * the allowance. script-src stays locked to 'self' plus the per-request nonce regardless.
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
                ."style-src 'self' 'nonce-{$nonce}'; "
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
