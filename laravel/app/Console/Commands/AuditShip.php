<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Support\Audit;
use App\Support\S3SigV4;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Task #230 — off-box shipping of the audit_log hash chain. Ships unshipped rows (ascending id,
 * capped at 5000/run) as NDJSON to the configured S3-compatible archive (see App\Support\S3SigV4)
 * and advances a high-water mark (settings.audit_shipped_through_id) ONLY after a successful
 * upload — so a failed/partial upload never loses a row's chance to ship, and re-running is
 * idempotent (rows already ≤ the mark are never re-selected). This mark is also what #232's
 * audit:prune command relies on to know a row is safely off-box before it may ever be deleted
 * locally: prune only considers id <= this mark eligible.
 *
 * Missing archive config is NOT a failure — it's a valid "not set up yet" state so the hourly
 * schedule (routes/console.php) doesn't spam failures on an environment that hasn't configured
 * AUDIT_S3_* yet.
 *
 *   php artisan audit:ship
 */
class AuditShip extends Command
{
    protected $signature = 'audit:ship';

    protected $description = 'Ship unshipped audit_log rows to the configured S3-compatible archive as NDJSON';

    private const BATCH_LIMIT = 5000;

    public function handle(): int
    {
        $settings = Setting::current();
        $mark = (int) $settings->audit_shipped_through_id;

        $rows = AuditLog::where('id', '>', $mark)->orderBy('id')->limit(self::BATCH_LIMIT)->get();

        if ($rows->isEmpty()) {
            $this->info('nothing to ship');

            return self::SUCCESS;
        }

        $firstId = $rows->first()->id;
        $lastId = $rows->last()->id;
        $count = $rows->count();

        $ndjson = $rows
            ->map(fn (AuditLog $row) => json_encode($row->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
            ->implode("\n")."\n";

        $now = now();
        $key = sprintf(
            'audit/%s/%s/%s/%d-%d-%d.ndjson',
            $now->format('Y'),
            $now->format('m'),
            $now->format('d'),
            $firstId,
            $lastId,
            $now->timestamp
        );

        try {
            $status = (new S3SigV4)->putObject($key, $ndjson);
        } catch (RuntimeException $e) {
            // Archive not configured (missing endpoint/bucket/keys) — expected on environments that
            // haven't set up AUDIT_S3_* yet. Not a failure: no mark advance, no error log noise.
            $this->warn('audit archive not configured — skipping');

            return self::SUCCESS;
        } catch (Throwable $e) {
            Log::error('audit.ship_failed', [
                'error' => $e->getMessage(), 'from' => $firstId, 'to' => $lastId, 'object' => $key,
            ]);

            return self::FAILURE;
        }

        if ($status < 200 || $status >= 300) {
            Log::error('audit.ship_failed', [
                'status' => $status, 'from' => $firstId, 'to' => $lastId, 'object' => $key,
            ]);

            return self::FAILURE;
        }

        // Update the memoized Setting::current() singleton in place (Eloquent update, not a raw
        // DB::table write) so any later Setting::current() call in the SAME process — including a
        // second audit:ship invocation in the same test — reads the advanced mark, not a stale cache.
        $settings->update(['audit_shipped_through_id' => $lastId]);

        Audit::log('audit.shipped', 'audit', null, [
            'from' => $firstId, 'to' => $lastId, 'count' => $count, 'object' => $key,
        ]);

        $this->info("shipped {$count} row(s) (#{$firstId}-#{$lastId}) to {$key}");

        return self::SUCCESS;
    }
}
