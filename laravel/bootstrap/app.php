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
    )
    ->withMiddleware(function (Middleware $middleware): void {
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
