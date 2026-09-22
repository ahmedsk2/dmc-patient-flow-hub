<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * §3.6 stopgap: the time budget for a report booklet rendered inside a web request.
 *
 * Every path that renders a booklet in a web request applies it: ReportsController's direct PDF
 * routes and GenerateMonthlyPdf — because this app runs no queue worker (QUEUE_CONNECTION=sync), a
 * "background" job executes inline in the same request, under the same limits.
 *
 * Deliberately a NO-OP on the CLI. PHP's CLI SAPI defaults max_execution_time to 0 (unlimited), and
 * the limit is per-PROCESS, not per-call — so setting it caps everything that runs afterwards in the
 * same process: in PHPUnit one Reports test would put the whole remaining suite on this budget
 * ("Premature end of PHP process"), and a real queue worker (itself CLI) would cap a legitimately
 * long render.
 */
class RenderBudget
{
    public const SECONDS = 120;

    public function apply(): void
    {
        if ($this->sapi() === 'cli') {
            return;
        }
        @ini_set('max_execution_time', (string) self::SECONDS);

        // Raise the web-only MySQL SELECT cap (config/database.php, DB_WEB_MAX_EXECUTION_MS, default
        // 60 s) to the same budget for this request, so a slow-but-valid aggregate is not killed halfway.
        $capMs = (int) config('database.web_statement_cap.ms', 0);
        if ($capMs > 0 && $capMs < self::SECONDS * 1000 && DB::connection()->getDriverName() === 'mysql') {
            DB::statement('SET SESSION MAX_EXECUTION_TIME='.(self::SECONDS * 1000));
        }
    }

    /** Overridable so the web branch can be exercised from the (CLI) test runner. */
    protected function sapi(): string
    {
        return PHP_SAPI;
    }
}
