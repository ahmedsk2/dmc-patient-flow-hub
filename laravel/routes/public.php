<?php

use App\Http\Controllers\HealthController;
use App\Http\Controllers\SecurityTxtController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Session-less machine endpoints
|--------------------------------------------------------------------------
|
| Loaded from bootstrap/app.php's `then:` hook — deliberately OUTSIDE the `web` group (no session,
| no cookies, no CSRF, no Inertia), exactly like Laravel's own `/up`. A monitor probing /health
| 60x a minute on a `web` route would mint a session file per hit; a crawler fetching security.txt
| would too. Nothing here is authenticated, and nothing here may ever carry PHI.
|
*/

// OBS-07: deep health probe (DB + storage + scheduler beacon). 200 ok / 503 degraded.
Route::get('/health', [HealthController::class, 'show'])
    ->middleware('throttle:60,1')->name('health');

// OPS-12: RFC 9116 vulnerability-disclosure contact.
Route::get('/.well-known/security.txt', [SecurityTxtController::class, 'show'])
    ->middleware('throttle:60,1')->name('security.txt');
