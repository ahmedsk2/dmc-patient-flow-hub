<?php

namespace Tests\Feature;

use App\Support\RenderBudget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * §3.6 / RES-01: the report render budget. In a web request it lifts PHP's time limit AND the
 * web-only MySQL SELECT cap (config/database.php, DB_WEB_MAX_EXECUTION_MS) to 120 s, so a heavy
 * booklet is not killed at 60 s; under the CLI it must do nothing at all (it would cap the rest of
 * the process — the PHPUnit suite, a future queue worker).
 */
class RenderBudgetTest extends TestCase
{
    use RefreshDatabase;

    /** The web branch, reachable from the CLI test runner. */
    private function webBudget(): RenderBudget
    {
        return new class extends RenderBudget
        {
            protected function sapi(): string
            {
                return 'fpm-fcgi';
            }
        };
    }

    /** @return list<string> the SQL statements $run issued */
    private function statementsDuring(callable $run): array
    {
        $seen = [];
        DB::listen(function ($q) use (&$seen) {
            $seen[] = $q->sql;
        });
        $run();

        return $seen;
    }

    public function test_it_is_a_no_op_under_the_cli(): void
    {
        config(['database.web_statement_cap.ms' => 60000]);
        $before = ini_get('max_execution_time');

        $sql = $this->statementsDuring(fn () => (new RenderBudget)->apply());

        $this->assertSame([], $sql);
        $this->assertSame($before, ini_get('max_execution_time'));
    }

    public function test_in_a_web_request_it_lifts_both_the_php_limit_and_the_select_cap(): void
    {
        config(['database.web_statement_cap.ms' => 60000]);
        $before = ini_get('max_execution_time');
        try {
            $sql = $this->statementsDuring(fn () => $this->webBudget()->apply());

            $this->assertContains('SET SESSION MAX_EXECUTION_TIME=120000', $sql);
            $this->assertSame('120', ini_get('max_execution_time'));
        } finally {
            // never leave this process (the rest of the suite) on a 120 s budget
            ini_set('max_execution_time', (string) $before);
            DB::statement('SET SESSION MAX_EXECUTION_TIME=0');
        }
    }

    public function test_it_leaves_the_select_cap_alone_when_the_cap_is_disabled_or_already_higher(): void
    {
        $before = ini_get('max_execution_time');
        try {
            foreach ([0, 300000] as $capMs) {
                config(['database.web_statement_cap.ms' => $capMs]);
                $sql = $this->statementsDuring(fn () => $this->webBudget()->apply());
                $this->assertNotContains('SET SESSION MAX_EXECUTION_TIME=120000', $sql, "cap {$capMs} ms");
            }
        } finally {
            ini_set('max_execution_time', (string) $before);
        }
    }
}
