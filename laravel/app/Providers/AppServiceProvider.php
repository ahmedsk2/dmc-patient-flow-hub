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
    }
}
