<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

/**
 * 2026-09 prod-readiness (OPS-12): RFC 9116 security.txt — the machine-readable disclosure contact
 * at the well-known path, so a researcher (or a scanner) finds the right inbox without guessing.
 * `Contact` comes from config('security.contact') (env SECURITY_CONTACT); `Expires` is computed a
 * year out at request time so the file can never go stale while the contact itself is live
 * config; `Canonical` is derived from the request root (APP_URL behind the trusted proxy).
 * Session-less (routes/public.php) — a crawler hit must not mint a session. The human-readable
 * policy is SECURITY.md (repo root + laravel/, kept identical).
 */
class SecurityTxtController extends Controller
{
    public function show(): Response
    {
        $lines = [
            'Contact: ' . (string) config('security.contact'),
            'Expires: ' . now()->addYear()->toIso8601ZuluString(),
            'Preferred-Languages: en, ar',
            'Canonical: ' . url('/.well-known/security.txt'),
        ];

        return response(implode("\n", $lines) . "\n", 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
