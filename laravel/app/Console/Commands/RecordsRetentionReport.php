<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * CMP-02 scaffold — docs/compliance/DATA-RETENTION.md item #3. READ-ONLY: this command has no
 * delete or anonymise mode at all, on purpose. It exists so the volume a retention decision would
 * apply to is visible before that decision is made, not to pre-build the mechanism for it.
 *
 * Prints COUNTS ONLY — never a name, MRN, admission id or any other identifier — so its output is
 * safe to paste anywhere the counts are needed (a ticket, this doc's checklist, a DPO email).
 *
 * Two numbers, both keyed off `--years=N` (an admission's episode is "closed more than N years
 * ago" when `discharge_date` — CLAUDE.md §6's "file closed" column, NOT `medical_discharge_date`
 * — is set and older than the cutoff; an admission with a NULL discharge_date is still open and
 * can never be "closed" by any N):
 *
 *   - admissions whose episode closed more than N years ago.
 *   - patients whose EVERY episode closed more than N years ago (no open admission, and no
 *     admission closed within the window) — i.e. the patient has nothing left that any retention
 *     rule would need to keep for.
 *
 * Both counts are taken with `DB::table` (bypassing the soft-delete scope on Admission/Patient on
 * purpose) — a soft-deleted row is still physically present PHI (§1.2 of the doc: "'Deleted from
 * the table' is not destruction"), so it still counts against what a real retention/destruction
 * step would have to reach.
 *
 * Requires --years=N and refuses to run without it — there is no sane default retention period to
 * assume (see the doc's item #1: the period is [NEEDS LEGAL CONFIRMATION]). The cutoff is bound
 * from PHP's `now()`, never MySQL's clock (scripts/clock-guard.php).
 *
 *   php artisan records:retention-report --years=10
 */
class RecordsRetentionReport extends Command
{
    protected $signature = 'records:retention-report {--years= : report on episodes closed more than this many whole years ago (required)}';

    protected $description = 'Read-only counts of how much data a retention/destruction rule would reach — no delete or anonymise mode';

    public function handle(): int
    {
        $raw = $this->option('years');
        if ($raw === null || $raw === '' || ! ctype_digit((string) $raw) || (int) $raw <= 0) {
            $this->error('--years=N is required (a positive whole number). Refusing to guess a retention period — '
                .'see docs/compliance/DATA-RETENTION.md item #1: the period is [NEEDS LEGAL CONFIRMATION].');

            return self::FAILURE;
        }
        $years = (int) $raw;
        $cutoff = now()->subYears($years)->toDateString();

        $closedAdmissions = DB::table('admissions')
            ->whereNotNull('discharge_date')
            ->where('discharge_date', '<', $cutoff)
            ->count();

        // A patient qualifies only when NONE of their admissions is still open or was closed
        // within the window — i.e. every episode they have is past the cutoff.
        $fullyOldPatients = DB::table('patients')
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('admissions')
                ->whereColumn('admissions.patient_id', 'patients.id'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('admissions')
                ->whereColumn('admissions.patient_id', 'patients.id')
                ->where(fn ($w) => $w->whereNull('admissions.discharge_date')
                    ->orWhere('admissions.discharge_date', '>=', $cutoff)))
            ->count();

        $this->info("Retention report — episodes closed more than {$years} year(s) ago (cutoff {$cutoff}):");
        $this->info("  admissions: {$closedAdmissions}");
        $this->info("  patients whose every episode is that old: {$fullyOldPatients}");
        $this->info('No deletion or anonymisation was performed — this command has no mechanism to do either. '
            .'Deletion stays unbuilt until hospital legal sets the retention period and the owner approves it.');

        return self::SUCCESS;
    }
}
