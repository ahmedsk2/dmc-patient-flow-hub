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
    private string $nonIcu = "(current_location <> 'ICU' OR current_location IS NULL)";

    public function index(Request $request): Response
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);
        $to = isset($data['to']) ? Carbon::parse($data['to']) : Carbon::today();
        $from = isset($data['from']) ? Carbon::parse($data['from']) : $to->copy()->startOfYear();
        if ($from->gt($to)) { [$from, $to] = [$to, $from]; }
        $f = $from->toDateString();
        $t = $to->toDateString();

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

        // 72-hour readmissions: a new admission within 72h of a prior REAL discharge for the same
        // patient. The prior episode must have ended in an actual discharge ('discharge from ward/ICU')
        // — ward<->ICU and specialty transfers are continuations of care, not discharges, so they are
        // excluded (otherwise same-day transfer rows inflate the metric). [definition: docs/METRICS.md]
        $readmissions = (int) DB::table('admissions as a')
            ->join('admissions as prev', function ($j) {
                $j->on('prev.patient_id', '=', 'a.patient_id')
                  ->whereColumn('prev.discharge_date', '<=', 'a.admit_date')
                  ->whereRaw('DATEDIFF(a.admit_date, prev.discharge_date) BETWEEN 0 AND 3')
                  ->whereColumn('prev.id', '<>', 'a.id')
                  ->whereIn('prev.transfer_type', ['discharge from ward', 'discharge from ICU']);
            })
            ->whereBetween('a.admit_date', [$f, $t])
            ->distinct()->count('a.id');

        // monthly series across the 12 months ending at $to
        $mStart = $to->copy()->startOfMonth()->subMonths(11)->toDateString();
        $admByMonth = $this->byMonth('admissions', 'admit_date', $mStart, $t, $this->nonIcu);
        $disByMonth = $this->byMonth('admissions', 'discharge_date', $mStart, $t, $this->nonIcu);
        $deathByMonth = $this->byMonth('admissions', 'discharge_date', $mStart, $t, "outcome = 'Dead'");
        $months = [];
        $cursor = $to->copy()->startOfMonth()->subMonths(11);
        for ($i = 0; $i < 12; $i++) { $months[] = $cursor->format('Y-m'); $cursor->addMonth(); }
        $monthly = [
            'labels' => array_map(fn ($m) => Carbon::createFromFormat('Y-m', $m)->format('M y'), $months),
            'admissions' => array_map(fn ($m) => (int) ($admByMonth[$m] ?? 0), $months),
            'discharges' => array_map(fn ($m) => (int) ($disByMonth[$m] ?? 0), $months),
            'deaths' => array_map(fn ($m) => (int) ($deathByMonth[$m] ?? 0), $months),
        ];

        // per-month KPI grid (tabular breakdown across the 12-month window)
        $icuByMonth = $this->byMonth('admissions', 'admit_date', $mStart, $t, "current_location = 'ICU'");
        $consByMonth = $this->byMonth('consultations', 'consultation_date', $mStart, $t, '1=1');
        $signByMonth = $this->byMonth('consultations', 'signoff_date', $mStart, $t, '1=1');
        $losByMonth = DB::table('admissions')->whereBetween('discharge_date', [$mStart, $t])->whereNotNull('admit_date')
            ->whereRaw($this->nonIcu)->whereRaw('DATEDIFF(discharge_date, admit_date) >= 0')
            ->selectRaw("DATE_FORMAT(discharge_date, '%Y-%m') m, ROUND(AVG(DATEDIFF(discharge_date, admit_date)), 1) los")
            ->groupBy('m')->pluck('los', 'm')->all();
        $kpiGrid = array_map(fn ($m) => [
            'label' => Carbon::createFromFormat('Y-m', $m)->format('M y'),
            'admissions' => (int) ($admByMonth[$m] ?? 0), 'discharges' => (int) ($disByMonth[$m] ?? 0),
            'icu' => (int) ($icuByMonth[$m] ?? 0), 'deaths' => (int) ($deathByMonth[$m] ?? 0),
            'consultations' => (int) ($consByMonth[$m] ?? 0), 'signoffs' => (int) ($signByMonth[$m] ?? 0),
            'avgLos' => (float) ($losByMonth[$m] ?? 0),
        ], $months);

        // discharge destinations (range) for a donut
        $dest = DB::table('admissions')->whereBetween('discharge_date', [$f, $t])
            ->selectRaw("COALESCE(NULLIF(TRIM(discharge_to), ''), 'Unspecified') dest, COUNT(*) c")
            ->groupBy('dest')->orderByDesc('c')->limit(8)->get();

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
            'destinations' => ['labels' => $dest->pluck('dest'), 'data' => $dest->pluck('c')],
        ]);
    }

    private function byMonth(string $table, string $col, string $from, string $to, string $extra): array
    {
        return DB::table($table)->whereBetween($col, [$from, $to])->whereRaw($extra)
            ->selectRaw("DATE_FORMAT($col, '%Y-%m') m, COUNT(*) c")->groupBy('m')->pluck('c', 'm')->all();
    }
}
