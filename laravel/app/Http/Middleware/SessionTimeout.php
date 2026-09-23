<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use App\Models\User;
use App\Support\Audit;
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
 *
 * 2026-09-23 walkthrough fix — also the ONE place that re-checks `users.active`/soft-delete per
 * request. ControlController ends the `sessions` rows immediately on deactivate/delete (mirroring
 * resetMfa), but that alone doesn't cover a session already loaded from a FILE-driver deployment, a
 * direct DB flip of `active`, or any future path that bypasses ControlController — so this defence
 * layer re-verifies independently of that. Deliberately does NOT gate on Auth::check(): `active`
 * has no global scope, so a merely-deactivated user still resolves via retrieveById() and
 * Auth::check() stays true — that is the branch this fix exists for, and it runs on every request.
 * The account_deleted/account_not_found branches are a narrower safety net, not the primary path:
 * `auth` (which precedes `session.timeout` in the route group, routes/web.php) already excludes a
 * soft-deleted or nonexistent id via Eloquent's SoftDeletingScope on retrieveById, so on today's
 * routing a genuinely deleted user's *next real request* is already redirected before this code
 * runs. They still matter for a stale `sessions` row surviving something like a legacy:import
 * truncate/rebuild (CLAUDE.md §10) or any future route that carries session.timeout without auth
 * ahead of it — hence keying off Auth::id(), which still resolves via the session fallback even
 * when the Eloquent lookup comes back empty.
 */
class SessionTimeout
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = Auth::id();
        if ($id === null) {
            return $next($request);
        }

        // withTrashed(): a soft-deleted account must be caught here too, not just excluded from
        // Auth::user() — we need the row (and its trashed/active state) to log an accurate reason.
        $user = User::withTrashed()->find($id);
        if (! $user || ! $user->active || $user->trashed()) {
            $reason = match (true) {
                ! $user => 'account_not_found',
                $user->trashed() => 'account_deleted',
                default => 'account_deactivated',
            };
            if ($user) {
                // The row still physically exists (deactivated, or soft-deleted with deleted_at
                // set) so its id satisfies audit_log's real FK (`actor_id` -> users.id,
                // nullOnDelete) — audit BEFORE logout, mirroring the idle/absolute block below,
                // while Auth::id() still resolves it.
                Audit::log('session.evicted', 'user', (string) $id, ['reason' => $reason]);
                Auth::guard('web')->logout();
            } else {
                // account_not_found: the row is genuinely gone, so $id would violate audit_log's
                // actor_id FK. logout() FIRST — SessionGuard::id() short-circuits to null once
                // loggedOut is true — so Audit::log records a null actor instead of crashing the
                // insert (a real fix: this used to throw a QueryException here and leave the
                // session live instead of redirecting).
                Auth::guard('web')->logout();
                Audit::log('session.evicted', 'user', (string) $id, ['reason' => $reason]);
            }

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->with('flash', [
                'type' => 'error',
                'message' => 'Your account access has changed — please sign in again or contact an administrator.',
            ]);
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
            // #233: audit the forced eviction BEFORE logout/invalidate — Audit::log reads
            // Auth::id()/Auth::user() internally, so the actor must still be resolvable from the
            // session at the point this runs. Absolute takes precedence in the reason, matching the
            // flash message below (a session can trip both checks at once).
            Audit::log('session.timeout', 'user', (string) Auth::id(), [
                'reason' => $absExpired ? 'absolute' : 'idle',
            ]);

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
