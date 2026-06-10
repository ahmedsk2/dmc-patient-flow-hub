<?php

namespace App\Http\Controllers;

use App\Models\ConsultationReason;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Unit statistics over a selectable date range. Queries are sargable: date filters use range
 * predicates on the indexed admit_date / discharge_date columns (no MONTH()/YEAR() wrapping that
 * would defeat the index — a defect called out in the legacy review). Definitions follow docs/METRICS.md.
 */
class StatisticsController extends Controller
{
    private string $nonIcu = \App\Models\Admission::NON_ICU_SQL;

    public function index(Request $request): Response
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'interval' => ['nullable', 'in:day,month,quarter'],
        ]);
        $to = isset($data['to']) ? Carbon::parse($data['to']) : Carbon::today();
        $from = isset($data['from']) ? Carbon::parse($data['from']) : $to->copy()->startOfYear();
        if ($from->gt($to)) { [$from, $to] = [$to, $from]; }
        $f = $from->toDateString();
        $t = $to->toDateString();
        $interval = $data['interval'] ?? 'month';

        // headline KPIs
        $admissions = (int) DB::table('admissions')->whereBetween('admit_date', [$f, $t])->whereRaw($this->nonIcu)->count();
        $discharges = (int) DB::table('admissions')->whereBetween('discharge_date', [$f, $t])->whereRaw($this->nonIcu)->count();
        $deaths = (int) DB::table('admissions')->where('outcome', 'Dead')->whereBetween('discharge_date', [$f, $t])->count();
        $icuAdmissions = (int) DB::table('admissions')->whereBetween('admit_date', [$f, $t])->where('current_location', 'ICU')->count();
        $consultations = (int) DB::table('consultations')->whereBetween('consultation_date', [$f, $t])->count();
        $signoffs = (int) DB::table('consultations')->whereBetween('signoff_date', [$f, $t])->count();

        $losAgg = DB::table('admissions')
            ->whereBetween('discharge_date', [$f, $t])->whereNotNull('admit_date')->whereRaw($this->nonIcu)
            ->whereRaw('DATEDIFF(discharge_date, admit_date) >= 0')
            ->selectRaw('AVG(DATEDIFF(discharge_date, admit_date)) avg_los, COUNT(*) n')->first();
        $avgLos = round((float) ($losAgg->avg_los ?? 0), 1);
        $mortalityRate = $discharges > 0 ? round($deaths / $discharges * 100, 1) : 0.0;

        // readmissions: a new admission within the CONFIGURED window of a prior REAL discharge for
        // the same patient (settings.readmission_window_days; clinically confirmed default 3 = the
        // "72-hour" rule). The prior episode must have ended in an actual discharge ('discharge from
        // ward/ICU') — ward<->ICU and specialty transfers are continuations of care, not discharges,
        // so they are excluded (otherwise same-day transfer rows inflate the metric).
        $readmitWindow = max(0, (int) (\App\Models\Setting::current()->readmission_window_days ?? 3));
        $readmissions = (int) DB::table('admissions as a')
            ->join('admissions as prev', function ($j) use ($readmitWindow) {
                $j->on('prev.patient_id', '=', 'a.patient_id')
                  ->whereColumn('prev.discharge_date', '<=', 'a.admit_date')
                  ->whereRaw('DATEDIFF(a.admit_date, prev.discharge_date) BETWEEN 0 AND ?', [$readmitWindow])
                  ->whereColumn('prev.id', '<>', 'a.id')
                  ->whereIn('prev.transfer_type', \App\Models\Admission::REAL_DISCHARGE_TYPES);
            })
            ->whereBetween('a.admit_date', [$f, $t])
            ->distinct()->count('a.id');

        // time-series + KPI grid over the SELECTED range, bucketed by interval (day/month/quarter)
        $buckets = $this->buckets($from, $to, $interval);
        $keys = array_column($buckets, 'key');
        $admBy = $this->seriesBy('admissions', 'admit_date', $f, $t, $interval, $this->nonIcu);
        $disBy = $this->seriesBy('admissions', 'discharge_date', $f, $t, $interval, $this->nonIcu);
        $deathBy = $this->seriesBy('admissions', 'discharge_date', $f, $t, $interval, "outcome = 'Dead'");
        $icuBy = $this->seriesBy('admissions', 'admit_date', $f, $t, $interval, "current_location = 'ICU'");
        $consBy = $this->seriesBy('consultations', 'consultation_date', $f, $t, $interval, '1=1');
        $signBy = $this->seriesBy('consultations', 'signoff_date', $f, $t, $interval, '1=1');
        $losBy = DB::table('admissions')->whereBetween('discharge_date', [$f, $t])->whereNotNull('admit_date')
            ->whereRaw($this->nonIcu)->whereRaw('DATEDIFF(discharge_date, admit_date) >= 0')
            ->selectRaw($this->keyExpr('discharge_date', $interval) . ' k, ROUND(AVG(DATEDIFF(discharge_date, admit_date)), 1) los')
            ->groupBy('k')->pluck('los', 'k')->all();

        $monthly = [
            'labels' => array_column($buckets, 'label'),
            'admissions' => array_map(fn ($k) => (int) ($admBy[$k] ?? 0), $keys),
            'discharges' => array_map(fn ($k) => (int) ($disBy[$k] ?? 0), $keys),
            'deaths' => array_map(fn ($k) => (int) ($deathBy[$k] ?? 0), $keys),
        ];
        $kpiGrid = array_map(fn ($b) => [
            'label' => $b['label'],
            'admissions' => (int) ($admBy[$b['key']] ?? 0), 'discharges' => (int) ($disBy[$b['key']] ?? 0),
            'icu' => (int) ($icuBy[$b['key']] ?? 0), 'deaths' => (int) ($deathBy[$b['key']] ?? 0),
            'consultations' => (int) ($consBy[$b['key']] ?? 0), 'signoffs' => (int) ($signBy[$b['key']] ?? 0),
            'avgLos' => (float) ($losBy[$b['key']] ?? 0),
        ], $buckets);

        // discharge destinations (range) for a donut — overall + per-consultant
        $dest = DB::table('admissions')->whereBetween('discharge_date', [$f, $t])
            ->selectRaw("COALESCE(NULLIF(TRIM(discharge_to), ''), 'Unspecified') dest, COUNT(*) c")
            ->groupBy('dest')->orderByDesc('c')->limit(8)->get();

        $byCons = [];
        DB::table('admissions as a')->join('users as u', 'u.id', '=', 'a.consultant_id')
            ->whereBetween('a.discharge_date', [$f, $t])
            ->selectRaw("COALESCE(u.full_name, u.name) name, COALESCE(NULLIF(TRIM(a.discharge_to), ''), 'Unspecified') dest, COUNT(*) c")
            ->groupByRaw("COALESCE(u.full_name, u.name), COALESCE(NULLIF(TRIM(a.discharge_to), ''), 'Unspecified')")
            ->get()->each(function ($r) use (&$byCons) { $byCons[$r->name][$r->dest] = (int) $r->c; });
        uasort($byCons, fn ($a, $b) => array_sum($b) <=> array_sum($a));
        $destByConsultant = [];
        foreach (array_slice($byCons, 0, 12, true) as $name => $dests) {
            arsort($dests);
            $destByConsultant[] = ['name' => $name, 'labels' => array_keys($dests), 'data' => array_values($dests)];
        }

        // LOS distribution
        $losRows = DB::table('admissions')->selectRaw('DATEDIFF(discharge_date, admit_date) los')
            ->whereBetween('discharge_date', [$f, $t])->whereNotNull('admit_date')->whereRaw($this->nonIcu)
            ->whereRaw('DATEDIFF(discharge_date, admit_date) >= 0')->pluck('los');
        $losBuckets = ['0–2' => 0, '3–5' => 0, '6–10' => 0, '11–20' => 0, '21+' => 0];
        foreach ($losRows as $l) {
            $l = (int) $l;
            if ($l <= 2) $losBuckets['0–2']++; elseif ($l <= 5) $losBuckets['3–5']++;
            elseif ($l <= 10) $losBuckets['6–10']++; elseif ($l <= 20) $losBuckets['11–20']++; else $losBuckets['21+']++;
        }

        // top diagnoses (range)
        $topDx = DB::table('admission_diagnoses as ad')
            ->join('admissions as a', 'a.id', '=', 'ad.admission_id')
            ->leftJoin('icd10 as i', 'i.code', '=', 'ad.icd10_code')
            ->whereBetween('a.admit_date', [$f, $t])
            ->selectRaw('ad.icd10_code code, MAX(i.name) name, COUNT(*) c')
            ->groupBy('ad.icd10_code')->orderByDesc('c')->limit(8)->get()
            ->map(fn ($r) => ['label' => $r->name ? mb_strimwidth($r->name, 0, 32, '…') : $r->code, 'value' => (int) $r->c]);

        // consultations by reason (decode JSON indication in PHP — small set)
        $reasonNames = ConsultationReason::pluck('name', 'id');
        $reasonTally = [];
        DB::table('consultations')->whereBetween('consultation_date', [$f, $t])->select('indication')
            ->orderBy('id')->chunk(500, function ($chunk) use (&$reasonTally, $reasonNames) {
                foreach ($chunk as $c) {
                    foreach (json_decode($c->indication ?? '', true) ?: [] as $id) {
                        $name = $reasonNames[$id] ?? null;
                        if ($name) { $reasonTally[$name] = ($reasonTally[$name] ?? 0) + 1; }
                    }
                }
            });
        arsort($reasonTally);
        $reasonTally = array_slice($reasonTally, 0, 8, true);

        // per-consultant admissions (range)
        $perConsultant = DB::table('admissions as a')->join('users as u', 'u.id', '=', 'a.consultant_id')
            ->whereBetween('a.admit_date', [$f, $t])
            ->selectRaw('COALESCE(u.full_name, u.name) name, COUNT(*) c')
            ->groupByRaw('COALESCE(u.full_name, u.name)')->orderByDesc('c')->limit(10)->get();

        // admission source mix
        $sourceMix = DB::table('admissions')->whereBetween('admit_date', [$f, $t])
            ->selectRaw("COALESCE(NULLIF(admitted_from, ''), 'Unknown') src, COUNT(*) c")
            ->groupBy('src')->orderByDesc('c')->limit(6)->get();

        return Inertia::render('Statistics/Index', [
            'range' => ['from' => $f, 'to' => $t],
            'kpis' => [
                'admissions' => $admissions, 'discharges' => $discharges, 'deaths' => $deaths,
                'mortalityRate' => $mortalityRate, 'icuAdmissions' => $icuAdmissions,
                'consultations' => $consultations, 'signoffs' => $signoffs,
                'avgLos' => $avgLos, 'readmissions' => $readmissions,
            ],
            'monthly' => $monthly,
            'los' => ['labels' => array_keys($losBuckets), 'data' => array_values($losBuckets)],
            'topDx' => $topDx,
            'reasons' => ['labels' => array_keys($reasonTally), 'data' => array_values($reasonTally)],
            'perConsultant' => $perConsultant,
            'sourceMix' => $sourceMix,
            'kpiGrid' => $kpiGrid,
            'interval' => $interval,
            'readmitWindow' => $readmitWindow,
            'destinations' => ['labels' => $dest->pluck('dest'), 'data' => $dest->pluck('c')],
            'destByConsultant' => $destByConsultant,
        ]);
    }

    /** SQL bucket-key expression for the chosen interval (sargable enough; grouped over a bounded range). */
    private function keyExpr(string $col, string $interval): string
    {
        return match ($interval) {
            'day' => "DATE($col)",
            'quarter' => "CONCAT(YEAR($col), '-Q', QUARTER($col))",
            default => "DATE_FORMAT($col, '%Y-%m')",
        };
    }

    private function seriesBy(string $table, string $col, string $f, string $t, string $interval, string $where): array
    {
        return DB::table($table)->whereBetween($col, [$f, $t])->whereRaw($where)
            ->selectRaw($this->keyExpr($col, $interval) . ' k, COUNT(*) c')->groupBy('k')->pluck('c', 'k')->all();
    }

    /** Ordered [{key,label}] buckets spanning [from,to] at the chosen interval. */
    private function buckets(Carbon $from, Carbon $to, string $interval): array
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
