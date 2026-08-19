<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Support\Audit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Task #232 — retention/prune for audit_log. DEFENSIVE by design: a row is eligible for deletion
 * ONLY when it has already shipped off-box (id <= settings.audit_shipped_through_id, the #230
 * high-water mark) AND has aged past the configured retention window
 * (settings.audit_retention_years, default 6 — 0 REFUSES to run at all, an explicit guard against
 * an accidental "retain nothing" config wiping the table). Defaults to a dry run that reports the
 * eligible count and deletes nothing; only `--confirm` actually deletes.
 *
 * Deletion goes through the query builder (DB::table), never the Eloquent model — AuditLog's
 * `deleting` hook throws to make audit_log append-only at the ORM layer, and this command is the
 * one deliberate, human-invoked exception to that rule.
 *
 * NEVER scheduled (see routes/console.php) — this is an operator-run command only.
 *
 *   php artisan audit:prune            # dry run — reports count, deletes nothing
 *   php artisan audit:prune --confirm  # deletes eligible rows
 */
class AuditPrune extends Command
{
    protected $signature = 'audit:prune {--confirm : actually delete eligible rows (default is a dry run)}';

    protected $description = 'Delete audit_log rows that are shipped off-box and past the retention window';

    public function handle(): int
    {
        $settings = Setting::current();
        $retentionYears = (int) $settings->audit_retention_years;
        $mark = (int) $settings->audit_shipped_through_id;

        if ($retentionYears <= 0) {
            $this->error('audit_retention_years is 0 — refusing to prune (set a positive retention period first).');

            return self::FAILURE;
        }

        if ($mark <= 0) {
            $this->error('nothing shipped off-box yet (audit_shipped_through_id = 0) — refusing to prune.');

            return self::FAILURE;
        }

        $cutoff = now()->subYears($retentionYears);
        $eligible = fn () => DB::table('audit_log')->where('id', '<=', $mark)->where('created_at', '<', $cutoff);

        $count = $eligible()->count();

        if (! $this->option('confirm')) {
            $this->info("DRY RUN: {$count} row(s) eligible for deletion (id <= {$mark}, created_at < {$cutoff->toDateTimeString()}). Re-run with --confirm to delete.");

            return self::SUCCESS;
        }

        if ($count === 0) {
            $this->info('nothing eligible for deletion.');

            return self::SUCCESS;
        }

        $deleted = $eligible()->delete();

        Audit::log('audit.pruned', 'audit', null, [
            'cutoff' => $cutoff->toDateTimeString(),
            'through_id' => $mark,
            'count' => $deleted,
        ]);

        $this->info("deleted {$deleted} row(s).");

        return self::SUCCESS;
    }
}
