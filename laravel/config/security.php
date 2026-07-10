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

];
