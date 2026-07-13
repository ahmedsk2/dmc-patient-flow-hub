<?php

namespace App\Providers;

use App\Models\Setting;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

/**
 * Overrides Laravel config from the single-row `settings` table at boot, so an admin can change
 * SMTP / timezone / app basics from the Control Panel without a redeploy. `.env` remains the
 * fallback: only NON-NULL columns override. Fail-safe — any read/decrypt error leaves `.env` in
 * force (never bricks a request). Runs on every request, so it works with or without config:cache.
 */
class RuntimeConfigServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        try {
            // Schema::hasTable AND the read must BOTH be inside this guard: during `composer install`
            // (package:discover), `artisan migrate`, or any deploy step that boots the app before the
            // database is reachable, even Schema::hasTable() THROWS (no connection / no sqlite file) —
            // it does not simply return false. Any such failure falls back to .env.
            if (! Schema::hasTable('settings')) {
                return;
            }
            // Deliberately NOT Setting::current(): that helper is memoized process-wide via
            // once() and firstOrCreate()s a row. This boot() runs on every application boot,
            // including every test's app boot — which happens BEFORE RefreshDatabase's
            // migrate:fresh/transaction setup. Going through Setting::current() here would
            // both write a row outside the test transaction (leaking into the persistent test
            // DB across runs) and poison the shared once() cache with an instance that a
            // subsequent migrate:fresh can orphan, breaking unrelated tests. A plain, uncached,
            // read-only fetch avoids both hazards; other callers still get the memoized row via
            // Setting::current() as before.
            $s = Setting::query()->orderBy('id')->first();
        } catch (\Throwable) {
            return;   // DB unavailable/unreadable (composer/migrate/deploy) -> keep .env
        }

        if ($s === null) {
            return;
        }

        $set = function (string $key, $val): void {
            if (filled($val)) {
                config([$key => $val]);
            }
        };

        if (filled($s->mail_mailer)) {
            config(['mail.default' => $s->mail_mailer]);
        }
        $set('mail.mailers.smtp.host', $s->mail_host);
        if ($s->mail_port !== null) {
            config(['mail.mailers.smtp.port' => (int) $s->mail_port]);
        }
        if (filled($s->mail_encryption)) {
            config(['mail.mailers.smtp.scheme' => $s->mail_encryption === 'ssl' ? 'smtps' : 'smtp']);
        }
        $set('mail.mailers.smtp.username', $s->mail_username);

        try {
            $pw = $s->mail_password;
        } catch (\Throwable) {
            $pw = null;
        }
        if (filled($pw)) {
            config(['mail.mailers.smtp.password' => $pw]);
        }

        $set('mail.from.address', $s->mail_from_address);
        $set('mail.from.name', $s->mail_from_name);

        if (filled($s->app_timezone)) {
            config(['app.timezone' => $s->app_timezone]);
            date_default_timezone_set($s->app_timezone);
        }
        $set('app.name', $s->app_name);
        $set('app.url', $s->app_url);
    }
}
