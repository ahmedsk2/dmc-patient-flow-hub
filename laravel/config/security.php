<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Content-Security-Policy mode
    |--------------------------------------------------------------------------
    |
    | Read once here (rather than via env() at request time in the middleware) so
    | `php artisan config:cache` works correctly in production — after config caching,
    | direct env() calls elsewhere in the app return null.
    |
    | enforce (default) — send `Content-Security-Policy` (blocking).
    | report            — send `Content-Security-Policy-Report-Only` (observe only).
    | off               — send neither CSP header; the other security headers still send.
    |
    */
    'csp_mode' => env('CSP_MODE', 'enforce'),

    /*
    |--------------------------------------------------------------------------
    | Vulnerability-disclosure contact (RFC 9116)
    |--------------------------------------------------------------------------
    |
    | 2026-09 prod-readiness (OPS-12): the `Contact:` URI served at /.well-known/security.txt
    | (SecurityTxtController). Must be a URI — `mailto:`, `https:` or `tel:` — never a bare
    | address. The default is a PLACEHOLDER on the app's own domain: set SECURITY_CONTACT to the
    | hospital's monitored security inbox before go-live, and keep SECURITY.md (repo root +
    | laravel/) in step with it.
    |
    */
    'contact' => env('SECURITY_CONTACT', 'mailto:info@dmc-im.com'),

];
