<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Auth throttle keyed by USERNAME+IP, not IP alone — the whole hospital sits behind one
        // NAT, so an IP-only bucket lets a single mistyping user lock everyone out. Brute-force
        // across many usernames is still bounded per target account. The MFA challenge carries
        // no username input; its pending identity lives in the session.
        RateLimiter::for('auth', function (Request $request) {
            // mb_strtolower, not strtolower (I18N-05): the login/username lookups themselves are
            // multibyte-safe (AuthController, UsernameReminderController), so a byte-wise strtolower()
            // here would fold a non-ASCII-cased identity (e.g. Turkish İ/i) differently from the
            // lookup and could let two case-variant identities miss sharing a throttle bucket.
            $identity = mb_strtolower(trim((string) ($request->input('username') ?: $request->input('email', ''))));
            if ($identity === '' && $request->hasSession()) {
                $identity = (string) $request->session()->get('mfa.pending.id', '');
            }

            return Limit::perMinute(5)->by($identity.'|'.$request->ip());
        });

        // 2026-07-11 auth-hardening — registration step endpoints (guest, multi-step, no stable
        // identity yet). Keyed by SESSION id (stable per browser across the multi-step POSTs) + IP,
        // NOT by username/email alone — the whole hospital can sit behind one NAT (see the 'auth'
        // limiter's comment above), and here there isn't even a confirmed identity to key on.
        RateLimiter::for('register', function (Request $request) {
            $session = $request->hasSession() ? $request->session()->getId() : '';

            return Limit::perMinute(10)->by($session.'|'.$request->ip());
        });

        // The email-code SEND step is the ONLY registration endpoint that dispatches mail to an
        // arbitrary, attacker-supplied address. 'register' above is session-keyed, so a script that
        // drops its cookies gets a fresh session (and a fresh pending row) on every request and
        // evades both that limiter AND the per-row send cap — an unbounded relay for mailing one-shot
        // codes to distinct victims. Add a SESSION-INDEPENDENT, IP-keyed bound a cookie-less client
        // can't rotate. Kept generous (registration is infrequent) so a whole hospital behind one NAT
        // can still onboard a batch of staff; an abuser is cut from unbounded to a few dozen distinct
        // victims per hour per IP, which forces a botnet rather than a one-liner.
        RateLimiter::for('register-email', function (Request $request) {
            return [
                Limit::perMinute(10)->by('reg-email:'.$request->ip()),
                Limit::perHour(60)->by('reg-email:'.$request->ip()),
            ];
        });

        // Step-up re-auth (authenticated). Keyed by the confirmed user id (+ IP) so brute-forcing the
        // step-up password/TOTP is rate-bounded without one NAT'd hospital locking users out of each
        // other. Paired with an in-session attempt cap in StepUpController (mirrors the MFA challenge).
        RateLimiter::for('stepup', function (Request $request) {
            return Limit::perMinute(5)->by('stepup:'.($request->user()?->id ?? $request->ip()));
        });

        // Existing-user email-verify gate (authenticated) — keyed by the confirmed user id + IP.
        RateLimiter::for('email-verify', function (Request $request) {
            $id = $request->user()?->id ?? ($request->hasSession() ? $request->session()->getId() : '');

            return Limit::perMinute(10)->by($id.'|'.$request->ip());
        });

        // PERF-08 — PHI-bearing pages (registry, dashboard, statistics, reports, patient/consultation
        // boards, active list, handover inbox+pages, the ICD-10 lookup, and the JSON endpoints those
        // pages poll) get a per-USER bucket, not per-IP: the whole hospital sits behind one NAT (see
        // the 'auth' limiter's comment above), so an IP-keyed bucket would let one heavy user's
        // dashboard tab starve every other clinician's requests. 240/min (4/s) comfortably clears
        // normal use — the dashboard's auto-refresh polls every 5 minutes and the ICD-10/quick-jump
        // typeaheads are 250ms-debounced, so no legitimate click/keystroke pattern gets near this
        // ceiling — while still bounding a scripted registry/board scrape. Falls back to IP only for
        // the handful of requests that can reach these routes without `$request->user()` populated
        // (defensive; the `auth` middleware already gates every one of them).
        RateLimiter::for('phi', function (Request $request) {
            return Limit::perMinute(240)->by('phi:'.($request->user()?->id ?? $request->ip()));
        });

        // Same per-user keying, much tighter: CSV/XLSX/PDF exports and report generation are the
        // highest-value bulk-exfiltration target (one export row = one patient), so they get their
        // own stricter bucket rather than sharing the 240/min page allowance. 20/min is far above any
        // legitimate admin's export cadence (these are deliberate, one-at-a-time clicks) but bounds a
        // scripted loop over registry/statistics/report exports.
        RateLimiter::for('phi-export', function (Request $request) {
            return Limit::perMinute(20)->by('phi-export:'.($request->user()?->id ?? $request->ip()));
        });
    }
}
