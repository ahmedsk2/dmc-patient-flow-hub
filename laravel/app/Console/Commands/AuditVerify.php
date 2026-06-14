<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use Illuminate\Console\Command;

/**
 * Phase 2 — Item 5: walk the audit_log hash chain and report the first broken link. Run nightly
 * by ops (cron) and in CI. Exit 0 = intact; exit 1 = a row was edited/deleted out from under the
 * chain (recomputed row_hash mismatch, or a prev_hash that doesn't match the predecessor).
 *
 *   php artisan audit:verify [--from=YYYY-MM-DD] [--to=YYYY-MM-DD]
 */
class AuditVerify extends Command
{
    protected $signature = 'audit:verify {--from= : only rows created on/after this date} {--to= : only rows created on/before this date}';
    protected $description = 'Verify the tamper-evident audit_log hash chain';

    public function handle(): int
    {
        $broken = [];
        $count = 0;
        $unhashed = 0;           // pre-chain rows written before the hash-chain migration (NULL row_hash)
        $prevRowHash = null;     // row_hash of the previously-walked HASHED row (chain expectation)
        $seenHashed = false;     // have we walked a hashed row yet? (chain linkage starts there)
        $lastCreatedAt = null;

        AuditLog::query()
            ->when($this->option('from'), fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
            ->when($this->option('to'), fn ($q, $d) => $q->whereDate('created_at', '<=', $d))
            ->orderBy('id')
            ->chunk(1000, function ($rows) use (&$broken, &$count, &$unhashed, &$prevRowHash, &$seenHashed, &$lastCreatedAt) {
                foreach ($rows as $row) {
                    $count++;
                    $lastCreatedAt = $row->created_at;
                    // pre-chain row (written before the migration / before backfill): NOT tampering —
                    // the chain simply starts after it. Counted separately and skipped from the walk.
                    if ($row->row_hash === null) {
                        $unhashed++;

                        continue;
                    }
                    // 1) row content integrity: recomputed hash must equal the stored one
                    $expected = hash('sha256', $row->canonical());
                    if (! hash_equals((string) $row->row_hash, $expected)) {
                        $broken[] = "Row #{$row->id}: content hash mismatch (row was edited after insertion).";
                    }
                    // 2) chain linkage: this row's prev_hash must equal the previous HASHED row's
                    //    row_hash (skipped for the first hashed row in the walked window).
                    if ($seenHashed && ! hash_equals((string) $row->prev_hash, (string) $prevRowHash)) {
                        $broken[] = "Row #{$row->id}: chain break (prev_hash does not match the preceding row — a row may have been deleted).";
                    }
                    $seenHashed = true;
                    $prevRowHash = $row->row_hash;
                }
            });

        if ($count === 0) {
            $this->info('No audit rows in range — nothing to verify.');

            return self::SUCCESS;
        }

        if ($unhashed > 0) {
            $this->warn("{$unhashed} pre-chain row(s) without a hash (written before the hash-chain migration / backfill) — not verifiable. Run the backfill before relying on full-history integrity.");
        }

        if ($broken) {
            $this->error("Audit chain BROKEN — {$count} rows walked, " . count($broken) . ' issue(s):');
            foreach ($broken as $b) {
                $this->line('  - ' . $b);
            }

            return self::FAILURE;
        }

        $through = $lastCreatedAt ? $lastCreatedAt->toDateTimeString() : 'n/a';
        $verified = $count - $unhashed;
        $this->info("Chain intact: {$verified} hashed row(s) verified through {$through}.");

        return self::SUCCESS;
    }
}
