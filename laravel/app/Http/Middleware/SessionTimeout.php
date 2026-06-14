<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phase 4 — Item 2: server-side idle + absolute session timeout. Runs on every authenticated
 * request (appended to the `auth` group). The client-side overlay in AppLayout is a UX convenience;
 * THIS is the authoritative enforcer.
 *
 *   idle   — now - session('last_activity_at') > idle_timeout_minutes*60  => logout
 *   absolute — now - session('session_started_at') > abs_timeout_minutes*60 (when abs > 0) => logout
 *
 * session_started_at is stamped at login (AuthController + MfaController). last_activity_at is
 * (re-)stamped here on every request that passes the checks.
 */
class SessionTimeout
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::check()) {
            return $next($request);
        }

        $settings = Setting::current();
        $idle = (int) ($settings->idle_timeout_minutes ?? 30);
        $abs = (int) ($settings->abs_timeout_minutes ?? 0);
        $now = now()->getTimestamp();

        $lastActivity = $request->session()->get('last_activity_at');
        $startedAt = $request->session()->get('session_started_at');

        $idleExpired = $idle > 0 && $lastActivity !== null && ($now - (int) $lastActivity) > $idle * 60;
        $absExpired = $abs > 0 && $startedAt !== null && ($now - (int) $startedAt) > $abs * 60;

        if ($idleExpired || $absExpired) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('flash', [
                'type' => 'error',
                'message' => $absExpired
                    ? 'Your session reached its maximum length — please sign in again.'
                    : 'You were signed out after a period of inactivity.',
            ]);
        }

        // passed the checks — refresh the activity stamp for the next request
        $request->session()->put('last_activity_at', $now);

        return $next($request);
    }
}
