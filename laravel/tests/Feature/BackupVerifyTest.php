<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * DATA-02: `backup:verify` reads the off-box backup heartbeat (db-backups/<db>/LATEST.json,
 * written by scripts/backup/db-backup.py after every successful upload) from the BACKUP bucket
 * and raises ONE `backup.stale` notification per active admin when the newest backup is older
 * than --max-age-hours, missing, or cannot be verified. A storage error is never a silent pass.
 * Mirrors AuditVerifyDaily's admin fan-out + open-incident de-duplication, and additionally
 * auto-resolves the open incident once a fresh backup is observed again so the NEXT lapse alerts.
 */
class BackupVerifyTest extends TestCase
{
    use RefreshDatabase;

    private const HEARTBEAT_URL = 'fake-s3.example.test/dmc-db-backups/db-backups/dmc_demo/LATEST.json';

    private const BINLOG_URL = 'fake-s3.example.test/dmc-db-backups/db-backups/dmc_demo/binlogs/LATEST.json';

    private const OBJECT = 'db-backups/dmc_demo/2026/09/dmc_demo-2026-09-03T021507Z.sql.gz.enc';

    private const BINLOG_OBJECT = 'db-backups/dmc_demo/binlogs/2026/09/binlog.000003-2026-09-03T144007Z.gz.enc';

    private function configure(): void
    {
        config([
            'services.audit_archive' => [
                'endpoint' => 'https://fake-s3.example.test',
                'bucket' => 'audit-archive',
                'region' => 'me-riyadh-1',
                'access_key' => 'AKIATESTKEY',
                'secret' => 'test-secret',
            ],
            'services.db_backup.bucket' => 'dmc-db-backups',
            'services.db_backup.prefix' => 'db-backups/dmc_demo',
            'services.db_backup.binlog_prefix' => 'db-backups/dmc_demo/binlogs',
            'services.db_backup.binlog_max_age_hours' => 2,
        ]);
    }

    private function user(int $role, int $active = 1): User
    {
        return User::create([
            'username' => 'bkv_'.substr(md5(uniqid('', true)), 0, 8),
            'name' => 'Backup User', 'password' => 'secret12345', 'role' => $role, 'active' => $active,
        ]);
    }

    private function heartbeat(Carbon $createdAt, string $object = self::OBJECT): string
    {
        return json_encode([
            'object' => $object,
            'bytes' => 123456789,
            'sha256_of_ciphertext' => str_repeat('ab', 32),
            'created_at' => $createdAt->clone()->utc()->format('Y-m-d\TH:i:s\Z'),
        ]);
    }

    /**
     * The hourly binlog shipper's own heartbeat (scripts/backup/binlog-ship.py, §10). Same field
     * names as the dump's, plus what tells a monitor the shipper is alive but not archiving.
     */
    private function binlogHeartbeat(Carbon $createdAt, array $failed = [], array $gaps = []): string
    {
        return json_encode([
            'object' => self::BINLOG_OBJECT,
            'bytes' => 4210688,
            'sha256_of_ciphertext' => str_repeat('cd', 32),
            'created_at' => $createdAt->clone()->utc()->format('Y-m-d\TH:i:s\Z'),
            'binlog' => 'binlog.000003',
            'shipped_this_run' => 1,
            'failed_this_run' => count($failed),
            'failed_binlogs' => $failed,
            'known_gaps' => $gaps,
        ]);
    }

    /**
     * Heartbeat body from LATEST.json; everything else (the HEAD of the object) answers 200.
     * The binlog heartbeat defaults to 404 = "point-in-time recovery not installed", which is the
     * production state until an operator adds the hourly cron and must never alert on its own.
     */
    private function fakeStorage(string|int $heartbeat, int $objectStatus = 200, string|int $binlog = 404): void
    {
        Http::fake([
            self::HEARTBEAT_URL => is_int($heartbeat) ? Http::response('', $heartbeat) : Http::response($heartbeat, 200),
            self::BINLOG_URL => is_int($binlog) ? Http::response('', $binlog) : Http::response($binlog, 200),
            'fake-s3.example.test/*' => Http::response('', $objectStatus),
        ]);
    }

    public function test_fresh_backup_sends_no_notification_and_reads_from_the_backup_bucket(): void
    {
        $this->configure();
        $this->fakeStorage($this->heartbeat(now()->subHours(4)));
        $this->user(User::ROLE_ADMIN);

        $this->artisan('backup:verify')->assertExitCode(0);

        $this->assertSame(0, Notification::where('type', 'backup.stale')->count());

        Http::assertSent(fn ($r) => $r->method() === 'GET'
            && $r->url() === 'https://'.self::HEARTBEAT_URL
            && str_contains($r->header('Authorization')[0], 'AWS4-HMAC-SHA256'));
        // the heartbeat's object is HEAD-checked too — a heartbeat pointing at nothing is not "fresh"
        Http::assertSent(fn ($r) => $r->method() === 'HEAD'
            && $r->url() === 'https://fake-s3.example.test/dmc-db-backups/'.self::OBJECT);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/audit-archive/'));
    }

    public function test_stale_backup_notifies_each_active_admin_exactly_once_and_nobody_else(): void
    {
        $this->configure();
        $this->fakeStorage($this->heartbeat(now()->subHours(30)));

        $admin1 = $this->user(User::ROLE_ADMIN);
        $admin2 = $this->user(User::ROLE_ADMIN);
        $inactiveAdmin = $this->user(User::ROLE_ADMIN, active: 0);
        $consultant = $this->user(User::ROLE_CONSULTANT);
        $observer = $this->user(User::ROLE_OBSERVER);

        $this->artisan('backup:verify')->assertExitCode(1);

        $this->assertSame(2, Notification::where('type', 'backup.stale')->count());
        $this->assertDatabaseHas('notifications', ['user_id' => $admin1->id, 'type' => 'backup.stale']);
        $this->assertDatabaseHas('notifications', ['user_id' => $admin2->id, 'type' => 'backup.stale']);
        foreach ([$inactiveAdmin, $consultant, $observer] as $excluded) {
            $this->assertDatabaseMissing('notifications', ['user_id' => $excluded->id, 'type' => 'backup.stale']);
        }

        $payload = Notification::where('user_id', $admin1->id)->where('type', 'backup.stale')->first()->payload;
        $this->assertSame('stale', $payload['reason']);
        $this->assertSame(self::OBJECT, $payload['object']);
        $this->assertSame(26, $payload['max_age_hours']);
        $this->assertGreaterThanOrEqual(29, $payload['age_hours']);
        $this->assertSame('dmc-db-backups', $payload['bucket']);
        // never leak credentials into a notification
        $this->assertStringNotContainsString('test-secret', json_encode($payload));
        $this->assertStringNotContainsString('AKIATESTKEY', json_encode($payload));
    }

    public function test_max_age_hours_option_widens_the_window(): void
    {
        $this->configure();
        $this->fakeStorage($this->heartbeat(now()->subHours(30)));
        $this->user(User::ROLE_ADMIN);

        $this->artisan('backup:verify', ['--max-age-hours' => 48])->assertExitCode(0);

        $this->assertSame(0, Notification::where('type', 'backup.stale')->count());
    }

    public function test_missing_heartbeat_notifies_each_active_admin(): void
    {
        $this->configure();
        $this->fakeStorage(404);
        $admin = $this->user(User::ROLE_ADMIN);
        $this->user(User::ROLE_ADMIN);

        $this->artisan('backup:verify')->assertExitCode(1);

        $this->assertSame(2, Notification::where('type', 'backup.stale')->count());
        $payload = Notification::where('user_id', $admin->id)->first()->payload;
        $this->assertSame('missing', $payload['reason']);
    }

    public function test_heartbeat_pointing_at_an_absent_object_counts_as_missing(): void
    {
        $this->configure();
        $this->fakeStorage($this->heartbeat(now()->subHours(2)), objectStatus: 404);
        $admin = $this->user(User::ROLE_ADMIN);

        $this->artisan('backup:verify')->assertExitCode(1);

        $payload = Notification::where('user_id', $admin->id)->where('type', 'backup.stale')->first()->payload;
        $this->assertSame('missing', $payload['reason']);
        $this->assertSame(self::OBJECT, $payload['object']);
    }

    public function test_storage_error_is_logged_and_notifies_never_a_silent_pass(): void
    {
        Log::shouldReceive('error')->once()->with('backup.verify_failed', \Mockery::type('array'));
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        $this->configure();
        $this->fakeStorage(500);
        $admin = $this->user(User::ROLE_ADMIN);

        $this->artisan('backup:verify')->assertExitCode(1);

        $payload = Notification::where('user_id', $admin->id)->where('type', 'backup.stale')->first()->payload;
        $this->assertSame('error', $payload['reason']);
        $this->assertStringContainsString('500', $payload['detail']);
        $this->assertStringNotContainsString('test-secret', json_encode($payload));
    }

    public function test_malformed_heartbeat_is_an_error_not_a_pass(): void
    {
        $this->configure();
        $this->fakeStorage('this is not json');
        $admin = $this->user(User::ROLE_ADMIN);

        $this->artisan('backup:verify')->assertExitCode(1);

        $payload = Notification::where('user_id', $admin->id)->where('type', 'backup.stale')->first()->payload;
        $this->assertSame('error', $payload['reason']);
    }

    public function test_unconfigured_storage_notifies_with_its_own_reason(): void
    {
        config([
            'services.audit_archive' => ['endpoint' => null, 'bucket' => null, 'region' => 'me-riyadh-1', 'access_key' => null, 'secret' => null],
            'services.db_backup.bucket' => 'dmc-db-backups',
        ]);
        Http::fake();
        $admin = $this->user(User::ROLE_ADMIN);

        $this->artisan('backup:verify')->assertExitCode(1);

        Http::assertNothingSent();
        $payload = Notification::where('user_id', $admin->id)->where('type', 'backup.stale')->first()->payload;
        $this->assertSame('unconfigured', $payload['reason']);
    }

    public function test_dedupes_against_an_already_open_stale_notification(): void
    {
        $this->configure();
        $this->fakeStorage($this->heartbeat(now()->subHours(30)));
        $admin = $this->user(User::ROLE_ADMIN);
        Notification::create([
            'user_id' => $admin->id, 'type' => 'backup.stale', 'created_at' => now()->subDay(),
            'payload' => ['reason' => 'stale'],
        ]);

        $this->artisan('backup:verify')->assertExitCode(1);

        $this->assertSame(1, Notification::where('user_id', $admin->id)->where('type', 'backup.stale')->count());
    }

    public function test_a_fresh_backup_resolves_open_stale_notifications_so_the_next_lapse_alerts_again(): void
    {
        $this->configure();
        // One fake whose heartbeat age is read at request time — a second Http::fake() would not
        // override the first stub (Laravel answers with the first matching stub it registered).
        $ageHours = 1;
        Http::fake([
            self::HEARTBEAT_URL => function () use (&$ageHours) {
                return Http::response($this->heartbeat(now()->subHours($ageHours)), 200);
            },
            self::BINLOG_URL => Http::response('', 404),
            'fake-s3.example.test/*' => Http::response('', 200),
        ]);
        $admin = $this->user(User::ROLE_ADMIN);
        $open = Notification::create([
            'user_id' => $admin->id, 'type' => 'backup.stale', 'created_at' => now()->subDay(),
            'payload' => ['reason' => 'stale'],
        ]);

        $this->artisan('backup:verify')->assertExitCode(0);

        $this->assertNotNull($open->fresh()->resolved_at);
        $this->assertSame(1, Notification::where('user_id', $admin->id)->where('type', 'backup.stale')->count());

        // ...and a subsequent lapse mints a new incident (the resolved one no longer blocks it)
        $ageHours = 40;
        $this->artisan('backup:verify')->assertExitCode(1);
        $this->assertSame(2, Notification::where('user_id', $admin->id)->where('type', 'backup.stale')->count());
    }

    // ---- point-in-time recovery: the hourly binlog shipper's heartbeat (§10) --------------------

    public function test_a_missing_binlog_heartbeat_means_not_installed_and_never_alerts(): void
    {
        $this->configure();
        $this->fakeStorage($this->heartbeat(now()->subHours(4)), binlog: 404);
        $this->user(User::ROLE_ADMIN);

        $this->artisan('backup:verify')
            ->expectsOutputToContain('NOT INSTALLED')
            ->assertExitCode(0);

        $this->assertSame(0, Notification::where('type', 'backup.stale')->count());
        Http::assertSent(fn ($r) => $r->method() === 'GET' && $r->url() === 'https://'.self::BINLOG_URL);
    }

    public function test_a_fresh_binlog_heartbeat_passes_and_is_read_from_the_backup_bucket(): void
    {
        $this->configure();
        $this->fakeStorage($this->heartbeat(now()->subHours(4)), binlog: $this->binlogHeartbeat(now()->subMinutes(20)));
        $this->user(User::ROLE_ADMIN);

        $this->artisan('backup:verify')->assertExitCode(0);

        $this->assertSame(0, Notification::where('type', 'backup.stale')->count());
        Http::assertSent(fn ($r) => $r->method() === 'GET'
            && $r->url() === 'https://'.self::BINLOG_URL
            && str_contains($r->header('Authorization')[0], 'AWS4-HMAC-SHA256'));
    }

    public function test_a_stale_binlog_heartbeat_alerts_every_active_admin(): void
    {
        $this->configure();
        $this->fakeStorage($this->heartbeat(now()->subHours(4)), binlog: $this->binlogHeartbeat(now()->subHours(9)));
        $admin = $this->user(User::ROLE_ADMIN);
        $this->user(User::ROLE_OBSERVER);

        $this->artisan('backup:verify')->assertExitCode(1);

        $this->assertSame(1, Notification::where('type', 'backup.stale')->count());
        $payload = Notification::where('user_id', $admin->id)->where('type', 'backup.stale')->first()->payload;
        $this->assertSame('binlog_stale', $payload['reason']);
        $this->assertSame(2, $payload['binlog_max_age_hours']);
        $this->assertGreaterThanOrEqual(8, $payload['binlog_age_hours']);
        $this->assertSame(self::BINLOG_OBJECT, $payload['binlog_object']);
        $this->assertStringContainsString('falling behind', $payload['detail']);
        $this->assertStringNotContainsString('test-secret', json_encode($payload));
    }

    public function test_the_binlog_window_is_configurable_by_option_and_by_config(): void
    {
        $this->configure();
        $this->fakeStorage($this->heartbeat(now()->subHours(4)), binlog: $this->binlogHeartbeat(now()->subHours(9)));
        $this->user(User::ROLE_ADMIN);

        $this->artisan('backup:verify', ['--binlog-max-age-hours' => 12])->assertExitCode(0);
        $this->assertSame(0, Notification::where('type', 'backup.stale')->count());

        config(['services.db_backup.binlog_max_age_hours' => 12]);
        $this->artisan('backup:verify')->assertExitCode(0);
        $this->assertSame(0, Notification::where('type', 'backup.stale')->count());
    }

    public function test_a_shipper_that_is_alive_but_failing_files_still_alerts(): void
    {
        // The dangerous case: created_at is fresh, so an age-only check would call this healthy
        // while binary logs quietly never reach the bucket.
        $this->configure();
        $failing = $this->binlogHeartbeat(now()->subMinutes(10), failed: ['binlog.000007', 'binlog.000008']);
        $this->fakeStorage($this->heartbeat(now()->subHours(4)), binlog: $failing);
        $admin = $this->user(User::ROLE_ADMIN);

        $this->artisan('backup:verify')->assertExitCode(1);

        $payload = Notification::where('user_id', $admin->id)->where('type', 'backup.stale')->first()->payload;
        $this->assertSame('binlog_failed', $payload['reason']);
        $this->assertSame(2, $payload['binlog_failed_this_run']);
        $this->assertStringContainsString('binlog.000007', $payload['detail']);
    }

    public function test_a_malformed_binlog_heartbeat_is_an_error_not_a_pass(): void
    {
        $this->configure();
        $this->fakeStorage($this->heartbeat(now()->subHours(4)), binlog: 'not json at all');
        $admin = $this->user(User::ROLE_ADMIN);

        $this->artisan('backup:verify')->assertExitCode(1);

        $payload = Notification::where('user_id', $admin->id)->where('type', 'backup.stale')->first()->payload;
        $this->assertSame('binlog_error', $payload['reason']);
    }

    public function test_a_stale_dump_is_reported_before_the_binlog_check_runs(): void
    {
        // The dump is the base of every recovery; its incident is the one that must be raised.
        $this->configure();
        $this->fakeStorage($this->heartbeat(now()->subHours(30)), binlog: $this->binlogHeartbeat(now()->subHours(9)));
        $admin = $this->user(User::ROLE_ADMIN);

        $this->artisan('backup:verify')->assertExitCode(1);

        $payload = Notification::where('user_id', $admin->id)->where('type', 'backup.stale')->first()->payload;
        $this->assertSame('stale', $payload['reason']);
        $this->assertArrayNotHasKey('binlog_age_hours', $payload);
    }

    public function test_is_scheduled_daily(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($e) => str_contains((string) $e->command, 'backup:verify'));

        $this->assertCount(1, $events, 'backup:verify must be scheduled exactly once');
        $this->assertMatchesRegularExpression('/^\d+ \d+ \* \* \*$/', $events->first()->expression, 'expected a daily cron expression');
    }
}
