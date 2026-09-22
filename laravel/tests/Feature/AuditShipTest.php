<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Task #230 — audit:ship uploads unshipped audit_log rows as NDJSON to the configured
 * S3-compatible archive (App\Support\S3SigV4) and advances settings.audit_shipped_through_id
 * ONLY on a successful (2xx) upload, so a failed upload never loses a row's chance to ship and
 * re-running is idempotent. This is exercised entirely against a fake HTTP transport
 * (Http::fake()) — S3SigV4's real signing logic still runs, only the network call is intercepted.
 */
class AuditShipTest extends TestCase
{
    use RefreshDatabase;

    private function actor(): User
    {
        return User::create([
            'username' => 'ashp_'.substr(md5(uniqid('', true)), 0, 8),
            'name' => 'Ship Actor', 'password' => 'secret12345', 'role' => User::ROLE_ADMIN, 'active' => 1,
        ]);
    }

    private function configureArchive(): void
    {
        config([
            'services.audit_archive' => [
                'endpoint' => 'https://fake-s3.example.test',
                'bucket' => 'audit-archive',
                'region' => 'me-riyadh-1',
                'access_key' => 'AKIATESTKEY',
                'secret' => 'test-secret',
            ],
        ]);
    }

    private function seedRows(int $n): void
    {
        $this->actingAs($this->actor());
        for ($i = 0; $i < $n; $i++) {
            Audit::log("test.seed.{$i}", 'admission', (string) $i);
        }
    }

    public function test_ships_unshipped_rows_advances_mark_and_writes_shipped_entry(): void
    {
        $this->configureArchive();
        Http::fake(['fake-s3.example.test/*' => Http::response('', 200)]);

        $this->seedRows(3);
        $lastId = (int) AuditLog::max('id');

        $this->artisan('audit:ship')->assertExitCode(0);

        $this->assertSame($lastId, (int) Setting::current()->audit_shipped_through_id);

        Http::assertSent(function ($request) {
            return $request->method() === 'PUT'
                && str_starts_with($request->url(), 'https://fake-s3.example.test/audit-archive/audit/')
                && $request->hasHeader('Authorization')
                && str_contains($request->header('Authorization')[0], 'AWS4-HMAC-SHA256')
                && str_contains((string) $request->body(), '"action":"test.seed.0"');
        });

        $shipped = AuditLog::where('action', 'audit.shipped')->first();
        $this->assertNotNull($shipped);
        $this->assertSame(3, (int) $shipped->details['count']);
        $this->assertSame($lastId, (int) $shipped->details['to']);
    }

    /**
     * True idempotency check: a row already covered by the mark must never appear in a later
     * shipment's payload. Note this does NOT settle to a literal "nothing to ship" on an
     * immediate second run — Audit::log('audit.shipped', ...) writes its own record into
     * audit_log, so the very next run always finds exactly that one new self-referential row
     * (harmless; it ships on the following run, same as any other row). What must never happen
     * is a row id appearing in two different shipped batches.
     */
    public function test_second_run_never_reships_a_row_already_covered_by_the_mark(): void
    {
        $this->configureArchive();
        Http::fake(['fake-s3.example.test/*' => Http::response('', 200)]);

        $this->seedRows(2);

        $this->artisan('audit:ship')->assertExitCode(0);
        $this->artisan('audit:ship')->assertExitCode(0);

        $bodies = collect(Http::recorded())->map(fn ($pair) => (string) $pair[0]->body())->values();
        $this->assertCount(2, $bodies, 'both runs found something to ship (the 2nd run ships only its predecessor\'s audit.shipped meta-row)');

        $idsPerRequest = $bodies->map(fn ($body) => collect(explode("\n", trim($body)))
            ->filter()
            ->map(fn ($line) => json_decode($line, true)['id'])
            ->all());

        $this->assertEmpty(
            array_intersect($idsPerRequest[0], $idsPerRequest[1]),
            'a row must never be shipped twice'
        );

        // the second batch is exactly the first run's own audit.shipped record — nothing else
        $this->assertCount(1, $idsPerRequest[1]);
        $meta = AuditLog::whereIn('id', $idsPerRequest[1])->first();
        $this->assertSame('audit.shipped', $meta->action);
    }

    public function test_no_new_activity_between_runs_still_advances_only_past_the_shipped_batch(): void
    {
        $this->configureArchive();
        Http::fake(['fake-s3.example.test/*' => Http::response('', 200)]);

        $this->seedRows(2);
        $seededIds = AuditLog::orderBy('id')->pluck('id')->all();

        $this->artisan('audit:ship')->assertExitCode(0);

        $this->assertSame(max($seededIds), (int) Setting::current()->audit_shipped_through_id);
        // none of the seeded ids get re-selected on a hypothetical immediate re-query
        $stillPending = AuditLog::where('id', '>', (int) Setting::current()->audit_shipped_through_id)->pluck('id')->all();
        $this->assertEmpty(array_intersect($seededIds, $stillPending));
    }

    public function test_failed_upload_does_not_advance_the_mark(): void
    {
        $this->configureArchive();
        Http::fake(['fake-s3.example.test/*' => Http::response('', 500)]);
        Log::shouldReceive('error')->once()->with('audit.ship_failed', \Mockery::type('array'));

        $this->seedRows(2);

        $this->artisan('audit:ship')->assertExitCode(1);

        $this->assertSame(0, (int) Setting::current()->audit_shipped_through_id);
        $this->assertSame(0, AuditLog::where('action', 'audit.shipped')->count());
    }

    public function test_unconfigured_archive_is_a_noop_exit_zero(): void
    {
        config(['services.audit_archive' => [
            'endpoint' => null, 'bucket' => null, 'region' => 'me-riyadh-1', 'access_key' => null, 'secret' => null,
        ]]);
        Http::fake();

        $this->seedRows(1);

        $this->artisan('audit:ship')
            ->expectsOutputToContain('audit archive not configured')
            ->assertExitCode(0);

        $this->assertSame(0, (int) Setting::current()->audit_shipped_through_id);
        Http::assertNothingSent();
    }

    public function test_no_rows_at_all_is_a_noop(): void
    {
        $this->configureArchive();
        Http::fake();

        $this->artisan('audit:ship')->expectsOutput('nothing to ship')->assertExitCode(0);
        Http::assertNothingSent();
    }

    /**
     * --status is the read-only escape hatch BACKUP-AND-RESTORE.md §5/§10.5 step 5 needs after a
     * restore: it must show the bookmark without ever advancing it, calling the archive, or
     * selecting a row to ship — a real `audit:ship` run right after a restore is exactly what an
     * operator must NOT do until the mark has been reconciled against the archive by hand.
     */
    public function test_status_prints_the_bookmark_and_pending_count_without_shipping(): void
    {
        $this->configureArchive();
        Http::fake(['fake-s3.example.test/*' => Http::response('', 200)]);

        $this->seedRows(3);
        // MySQL's AUTO_INCREMENT does not roll back with the transaction between tests, so the id
        // values themselves are not asserted — only that the count of rows actually above the mark
        // (0, since nothing has ever shipped in this fresh RefreshDatabase table) is right.
        $lastId = (int) AuditLog::max('id');
        $count = AuditLog::count();

        $this->artisan('audit:ship', ['--status' => true])
            ->expectsOutputToContain("audit_shipped_through_id=0 max_audit_log_id={$lastId} pending={$count}")
            ->assertExitCode(0);

        Http::assertNothingSent();
        $this->assertSame(0, (int) Setting::current()->audit_shipped_through_id, 'a status check must never advance the mark');
        $this->assertSame(0, AuditLog::where('action', 'audit.shipped')->count(), 'a status check must never write an audit.shipped row');
    }

    public function test_status_reflects_the_mark_after_a_real_ship(): void
    {
        $this->configureArchive();
        Http::fake(['fake-s3.example.test/*' => Http::response('', 200)]);

        $this->seedRows(2);
        $this->artisan('audit:ship')->assertExitCode(0);
        $mark = (int) Setting::current()->audit_shipped_through_id;
        // The ship run's own `Audit::log('audit.shipped', …)` row is written AFTER the batch it
        // shipped was selected, so it is itself unshipped — the mark sits one row behind the true
        // max id straight after a run, exactly as test_second_run_never_reships_… documents.
        $maxId = (int) AuditLog::max('id');
        $this->assertSame($mark + 1, $maxId, 'the audit.shipped meta-row is the one row left pending');

        Http::fake(['fake-s3.example.test/*' => Http::response('', 200)]); // reset the recorder

        $this->artisan('audit:ship', ['--status' => true])
            ->expectsOutputToContain("audit_shipped_through_id={$mark} max_audit_log_id={$maxId} pending=1")
            ->assertExitCode(0);

        Http::assertNothingSent();
    }

    public function test_status_on_an_empty_table_reports_zeros(): void
    {
        $this->configureArchive();
        Http::fake();

        $this->artisan('audit:ship', ['--status' => true])
            ->expectsOutputToContain('audit_shipped_through_id=0 max_audit_log_id=0 pending=0')
            ->assertExitCode(0);

        Http::assertNothingSent();
    }
}
