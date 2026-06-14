<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3 — §3.1: the shared analytics query helpers, extracted so that both
 * StatisticsController and ReportsController build their bucketed time-series from the SAME
 * code path. The "highest-leverage refactor of the phase" (spec risks-&-sequencing note): it
 * lets ReportsController::consultantPdf reuse StatisticsController::physician without
 * duplicating buckets()/keyExpr()/seriesBy().
 *
 * Every metric routed through these helpers stays sargable: date filters are range predicates
 * over the indexed admit_date / discharge_date columns; the bucket KEY expression
 * (DATE / DATE_FORMAT / QUARTER) is only ever in the SELECT/GROUP BY, never wrapped around the
 * filter column. The day-interval bucket list is capped (~370) — the same cap that bounds the
 * year-over-year comparison series.
 */
trait MetricQueries
{
    /** SQL bucket-key expression for the chosen interval (sargable: only in SELECT/GROUP BY). */
    protected function keyExpr(string $col, string $interval): string
    {
        return match ($interval) {
            'day' => "DATE($col)",
            'quarter' => "CONCAT(YEAR($col), '-Q', QUARTER($col))",
            default => "DATE_FORMAT($col, '%Y-%m')",
        };
    }

    /** Grouped COUNT(*) keyed by interval bucket — [bucketKey => count]. */
    protected function seriesBy(string $table, string $col, string $f, string $t, string $interval, string $where): array
    {
        return DB::table($table)->whereBetween($col, [$f, $t])->whereRaw($where)
            ->selectRaw($this->keyExpr($col, $interval) . ' k, COUNT(*) c')->groupBy('k')->pluck('c', 'k')->all();
    }

    /** Ordered [{key,label}] buckets spanning [from,to] at the chosen interval (day capped ~370). */
    protected function buckets(Carbon $from, Carbon $to, string $interval): array
    {
        $out = [];
        if ($interval === 'day') {
            $c = $from->copy()->startOfDay();
            while ($c->lte($to) && count($out) <= 370) { $out[] = ['key' => $c->toDateString(), 'label' => $c->format('d M')]; $c->addDay(); }
        } elseif ($interval === 'quarter') {
            $c = $from->copy()->firstOfQuarter();
            while ($c->lte($to)) { $out[] = ['key' => $c->year . '-Q' . $c->quarter, 'label' => 'Q' . $c->quarter . ' ' . $c->year]; $c->addQuarter(); }
        } else {
            $c = $from->copy()->startOfMonth();
            while ($c->lte($to)) { $out[] = ['key' => $c->format('Y-m'), 'label' => $c->format('M y')]; $c->addMonth(); }
        }

        return $out;
    }
}
