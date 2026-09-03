<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // 2026-09 prod-readiness: session-less machine endpoints (/health deep probe, RFC 9116
        // security.txt) registered OUTSIDE the `web` group, exactly like `/up` above — see
        // routes/public.php for why. `/up` itself is untouched (Coolify polls it).
        then: fn () => \Illuminate\Support\Facades\Route::group([], __DIR__.'/../routes/public.php'),
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Behind Cloudflare/Traefik TLS termination: trust forwarded headers so the app detects
        // HTTPS and generates https:// URLs (without it the /mfa/challenge endpoint the login JS
        // calls comes out http:// and the browser blocks it as mixed content / CSP violation).
        //
        // Pinned to a specific proxy set — NOT `at: '*'`. Trusting X-Forwarded-For from ANY source
        // let a client that reaches the origin directly spoof its own IP: that defeats the
        // IP-keyed login/MFA throttle (rotate the header, brute-force freely) and poisons the
        // audit-log `ip`. We trust only (1) the local TLS-terminating reverse proxy (Traefik, on a
        // private/loopback address) and (2) Cloudflare's published edge ranges in front of it. A
        // request arriving straight from the internet has a non-Cloudflare PUBLIC source IP, so its
        // forged XFF is ignored and $request->ip() falls back to the real peer.
        //   Maintenance: refresh the Cloudflare CIDRs from https://www.cloudflare.com/ips/ if they
        //   change (rare). Traefik must itself only accept/forward from Cloudflare — infra concern.
        $middleware->trustProxies(at: [
            // local reverse proxy (same host / private network)
            '127.0.0.1', '::1', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', 'fc00::/7',
            // Cloudflare IPv4 — https://www.cloudflare.com/ips-v4
            '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
            '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
            '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
            '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
            // Cloudflare IPv6 — https://www.cloudflare.com/ips-v6
            '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
            '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
        ]);

        // Prod-readiness closeout, Item 1: CSP + static security headers on every web response.
        // PREPENDED (outermost web slice), not appended: error responses produced by inner
        // middleware — e.g. the 419 a CSRF token-mismatch renders at the ValidateCsrfToken
        // slice — travel back OUT through earlier slices only, so only an outermost
        // SecurityHeaders sees and stamps them. (Router-level 404s never enter the group at
        // all; those static error pages carry no scripts or PHI.)
        $middleware->web(prepend: [
            \App\Http\Middleware\SecurityHeaders::class,
        ]);
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
        ]);
        // Browsers POST CSP violation reports with neither credentials nor a CSRF token —
        // the sink route (throttled, log-only) must be exempt or every report 419s.
        $middleware->validateCsrfTokens(except: ['csp-report']);
        $middleware->alias([
            'admin' => \App\Http\Middleware\EnsureAdmin::class,
            'email.verify' => \App\Http\Middleware\EnsureEmailVerified::class, // 2026-07-11 auth-hardening
            'mfa.enroll' => \App\Http\Middleware\EnsureMfaEnrolled::class,
            'pwd' => \App\Http\Middleware\EnsurePasswordNotExpired::class,
            'session.timeout' => \App\Http\Middleware\SessionTimeout::class,   // Phase 4 — Item 2
            'stepup' => \App\Http\Middleware\RequireStepUp::class,             // Phase 4 — Item 4
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
