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
