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
            $identity = strtolower(trim((string) ($request->input('username') ?: $request->input('email', ''))));
            if ($identity === '' && $request->hasSession()) {
                $identity = (string) $request->session()->get('mfa.pending.id', '');
            }

            return Limit::perMinute(5)->by($identity . '|' . $request->ip());
        });

        // 2026-07-11 auth-hardening — registration step endpoints (guest, multi-step, no stable
        // identity yet). Keyed by SESSION id (stable per browser across the multi-step POSTs) + IP,
        // NOT by username/email alone — the whole hospital can sit behind one NAT (see the 'auth'
        // limiter's comment above), and here there isn't even a confirmed identity to key on.
        RateLimiter::for('register', function (Request $request) {
            $session = $request->hasSession() ? $request->session()->getId() : '';

            return Limit::perMinute(10)->by($session . '|' . $request->ip());
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
                Limit::perMinute(10)->by('reg-email:' . $request->ip()),
                Limit::perHour(60)->by('reg-email:' . $request->ip()),
            ];
        });

        // Existing-user email-verify gate (authenticated) — keyed by the confirmed user id + IP.
        RateLimiter::for('email-verify', function (Request $request) {
            $id = $request->user()?->id ?? ($request->hasSession() ? $request->session()->getId() : '');

            return Limit::perMinute(10)->by($id . '|' . $request->ip());
        });
    }
}
