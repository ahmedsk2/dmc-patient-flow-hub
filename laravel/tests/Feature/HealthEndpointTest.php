<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * 2026-09 prod-readiness (OBS-07): GET /health is the DEEP probe — it actually exercises the DB,
 * checks the framework storage dir is writable, and reads the scheduler liveness beacon that
 * `scheduler:heartbeat` writes every minute (this project has already suffered a silently-dead
 * scheduler; a stale beacon is how a monitor now notices). Laravel's stock `/up` (static, Coolify
 * uses it) is left untouched. Unauthenticated, session-less, throttled, PHI-free, secret-free.
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
        $this->assertSame(['db', 'storage_writable', 'scheduler'], array_keys($response->json('checks')));
        $this->assertSame(['last_run_at', 'stale'], array_keys($response->json('checks.scheduler')));
        $this->assertSame(['version', 'timezone'], array_keys($response->json('app')));

        $this->assertStringNotContainsString((string) config('app.key'), $body);
        $this->assertStringNotContainsString((string) config('database.connections.mysql.database'), $body);
        $this->assertStringNotContainsString((string) config('database.connections.mysql.username'), $body);
    }

    public function test_stock_up_endpoint_is_untouched(): void
    {
        $this->get('/up')->assertOk();
    }
}
