<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * 2026-09 prod-readiness (OBS-07): GET /health is the DEEP probe — it actually exercises the DB,
 * checks the framework storage dir is writable, and reads the scheduler liveness beacon that
 * `scheduler:heartbeat` writes every minute (this project has already suffered a silently-dead
 * scheduler; a stale beacon is how a monitor now notices). Laravel's stock `/up` (static, Coolify
 * uses it) is left untouched. Unauthenticated, session-less, throttled, PHI-free, secret-free.
 *
 * R2 adds a `clock` check: PHP's UTC now vs. the DB's UTC_TIMESTAMP(), degraded past 120s of skew.
 * The clock-skew tests fake the skew with Carbon::setTestNow() rather than the DB's clock (there is
 * no local knob for "what time does 127.0.0.1:3306 think it is" in a feature test) — moving PHP's
 * idea of "now" away from the real wall clock has the identical effect on the computed skew, since
 * HealthController compares PHP's now() against the DB's OWN clock, not against a fixed value.
 */
class HealthEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget('scheduler.heartbeat');
    }

    public function test_healthy_system_returns_200_ok_with_every_check_passing(): void
    {
        Artisan::call('scheduler:heartbeat');

        $response = $this->getJson('/health');

        $response->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.db', true)
            ->assertJsonPath('checks.storage_writable', true)
            ->assertJsonPath('checks.scheduler.stale', false)
            ->assertJsonPath('app.timezone', config('app.timezone'));

        $this->assertNotNull($response->json('checks.scheduler.last_run_at'));
        $this->assertNotSame('', (string) $response->json('app.version'), 'version is a non-empty string ("unknown" when APP_VERSION is unset)');
    }

    public function test_clock_skew_against_the_real_db_clock_is_reported_and_within_tolerance(): void
    {
        Artisan::call('scheduler:heartbeat');

        $response = $this->getJson('/health');

        $response->assertOk()->assertJsonPath('checks.clock.degraded', false);
        $skew = $response->json('checks.clock.skew_seconds');
        // a whole-number float round-trips through JSON as a bare integer (0, not 0.0) — assert
        // numeric, not float, so an exact-zero skew (test DB and PHP on the same machine) still passes
        $this->assertIsNumeric($skew);
        // real skew here is milliseconds, nowhere near the 120s threshold below
        $this->assertLessThan(120, abs((float) $skew));
    }

    public function test_a_faked_clock_skew_past_120_seconds_returns_503_degraded(): void
    {
        Artisan::call('scheduler:heartbeat');

        // PHP's clock frozen 10 real minutes ahead of the (unfaked, real) DB clock — HealthController
        // computes skew_seconds = db_utc_now - php_now, so this must come back strongly negative.
        Carbon::setTestNow(Carbon::now()->addMinutes(10));
        try {
            $response = $this->getJson('/health');
        } finally {
            Carbon::setTestNow();
        }

        $response->assertStatus(503)
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('checks.db', true)
            ->assertJsonPath('checks.clock.degraded', true);
        $this->assertLessThan(-120, $response->json('checks.clock.skew_seconds'));
    }

    public function test_a_faked_clock_skew_under_120_seconds_stays_ok(): void
    {
        Artisan::call('scheduler:heartbeat');

        Carbon::setTestNow(Carbon::now()->addSeconds(30));
        try {
            $response = $this->getJson('/health');
        } finally {
            Carbon::setTestNow();
        }

        $response->assertOk()->assertJsonPath('checks.clock.degraded', false);
    }

    public function test_version_comes_from_config_app_version(): void
    {
        Artisan::call('scheduler:heartbeat');
        config(['app.version' => 'abc1234']);

        $this->getJson('/health')->assertOk()->assertJsonPath('app.version', 'abc1234');
    }

    public function test_db_failure_returns_503_degraded_with_db_false(): void
    {
        Artisan::call('scheduler:heartbeat');

        // Point the default connection at a name that is not configured: the probe's connect
        // throws immediately (deterministic, no network timeout) and must be caught, not 500.
        $default = config('database.default');
        config(['database.default' => 'health-test-missing']);
        try {
            $response = $this->getJson('/health');
        } finally {
            config(['database.default' => $default]);
        }

        $response->assertStatus(503)
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('checks.db', false)
            ->assertJsonPath('checks.storage_writable', true)
            ->assertJsonPath('checks.scheduler.stale', false);
    }

    public function test_stale_scheduler_heartbeat_returns_503_with_stale_true(): void
    {
        Cache::forever('scheduler.heartbeat', now()->subMinutes(6)->toIso8601String());

        $response = $this->getJson('/health');

        $response->assertStatus(503)
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('checks.db', true)
            ->assertJsonPath('checks.scheduler.stale', true);
        $this->assertNotNull($response->json('checks.scheduler.last_run_at'), 'a stale beacon still reports WHEN the scheduler last ran');
    }

    public function test_a_heartbeat_just_under_five_minutes_old_is_not_stale(): void
    {
        Cache::forever('scheduler.heartbeat', now()->subMinutes(4)->toIso8601String());

        $this->getJson('/health')->assertOk()->assertJsonPath('checks.scheduler.stale', false);
    }

    public function test_missing_heartbeat_is_reported_stale_with_a_null_last_run(): void
    {
        $this->getJson('/health')->assertStatus(503)
            ->assertJsonPath('checks.scheduler.stale', true)
            ->assertJsonPath('checks.scheduler.last_run_at', null);
    }

    public function test_heartbeat_command_writes_the_cache_key(): void
    {
        $this->assertNull(Cache::get('scheduler.heartbeat'));

        $this->assertSame(0, Artisan::call('scheduler:heartbeat'));

        $this->assertNotNull(Cache::get('scheduler.heartbeat'));
    }

    public function test_heartbeat_command_is_scheduled_every_minute(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) ($e->command ?? ''), 'scheduler:heartbeat'));

        $this->assertNotNull($event, 'scheduler:heartbeat must be registered in routes/console.php');
        $this->assertSame('* * * * *', $event->expression);
    }

    public function test_endpoint_needs_no_session_or_csrf_and_sets_no_session_cookie(): void
    {
        Artisan::call('scheduler:heartbeat');

        // Plain GET: no cookies, no CSRF token, no auth — exactly what an external monitor sends.
        $response = $this->get('/health');

        $response->assertOk()->assertCookieMissing(config('session.cookie'));
        $this->assertNull($response->headers->get('Set-Cookie'), 'a 60/min probe on a session-bearing route would mint a session per hit');
    }

    public function test_endpoint_is_throttled_to_sixty_per_minute(): void
    {
        Artisan::call('scheduler:heartbeat');

        for ($i = 0; $i < 60; $i++) {
            $this->getJson('/health')->assertOk();
        }

        $this->getJson('/health')->assertStatus(429);
    }

    public function test_body_carries_only_the_documented_keys_and_no_secrets(): void
    {
        Artisan::call('scheduler:heartbeat');

        $response = $this->getJson('/health');
        $body = (string) $response->getContent();

        $this->assertSame(['status', 'checks', 'app'], array_keys($response->json()));
        $this->assertSame(['db', 'storage_writable', 'scheduler', 'clock'], array_keys($response->json('checks')));
        $this->assertSame(['last_run_at', 'stale'], array_keys($response->json('checks.scheduler')));
        $this->assertSame(['skew_seconds', 'degraded'], array_keys($response->json('checks.clock')));
        $this->assertSame(['version', 'timezone'], array_keys($response->json('app')));

        $this->assertStringNotContainsString((string) config('app.key'), $body);
        $this->assertStringNotContainsString((string) config('database.connections.mysql.database'), $body);
        $this->assertStringNotContainsString((string) config('database.connections.mysql.username'), $body);
    }

    public function test_stock_up_endpoint_is_untouched(): void
    {
        $this->get('/up')->assertOk();
    }

    /**
     * /health sits outside the `web` group, so none of SecurityHeaders (CSP, X-Frame-Options,
     * HSTS...) reaches it — that is by design (session-less, see routes/public.php). It still needs
     * its own two: a monitoring probe must not be cached, and nosniff is free on a JSON body.
     */
    public function test_response_carries_its_own_sensible_headers(): void
    {
        Artisan::call('scheduler:heartbeat');

        $response = $this->get('/health');

        // Symfony's ResponseHeaderBag computes Cache-Control rather than echoing the literal string
        // set on it: a directive set with neither public/private/s-maxage present gets ", private"
        // appended automatically (conservative-by-default) — so "no-store" (set below) is correctly
        // reported back as "no-store, private", matching what authenticated `web` pages already send.
        $response->assertHeader('Cache-Control', 'no-store, private');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
    }
}
