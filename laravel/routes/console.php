<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Phase 3 — §3.3: on the 1st of each month, email the PRIOR month's booklet to active recipients.
Schedule::call(function () {
    $prior = \Illuminate\Support\Carbon::today()->subMonth();
    \App\Jobs\GenerateMonthlyReport::dispatch($prior->year, $prior->month);
})->monthlyOn(1, '06:00')->name('monthly-report-email')->withoutOverlapping();

// Phase 4 — Item 6: daily data-quality digest for admins (one notification per run when anything
// needs review). Read-only; no auto-fix.
Schedule::command('dq:notify')->dailyAt('07:00')->name('data-quality-digest')->withoutOverlapping();

// Task #231: nightly tamper-evident hash-chain integrity check. Runs before the 07:00 data-quality
// digest so a broken chain is flagged as early as possible. Silent on success; on failure it
// Log::critical's, notifies every active admin in-app, and best-effort emails report recipients.
Schedule::command('audit:verify-daily')->dailyAt('02:30')->name('audit-integrity-check')->withoutOverlapping();

// DATA-02: daily proof that last night's off-box encrypted DB backup (host cron, 02:15 host time,
// scripts/backup/db-backup.py) actually landed — reads the LATEST.json heartbeat from the backup
// bucket and alerts every active admin in-app (backup.stale) when it is older than 26h, missing,
// or unverifiable. Runs well after the backup window in either app timezone (UTC or Asia/Riyadh).
Schedule::command('backup:verify')->dailyAt('06:30')->name('backup-verify')->withoutOverlapping();

// Task #230: off-box audit-log shipping. No-ops (warn + exit 0) when AUDIT_S3_* isn't configured,
// so this is safe to leave scheduled everywhere. audit:prune (#232) is deliberately NEVER
// scheduled — it deletes rows and is operator-run only, gated behind --confirm.
Schedule::command('audit:ship')->hourly()->name('audit-ship')->withoutOverlapping();
