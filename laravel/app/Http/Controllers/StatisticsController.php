<?php

namespace App\Http\Controllers;

use App\Models\Admission;
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
    private string $nonIcu = Admission::NON_ICU_SQL;

    public function index(Request $request): Response
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'interval' => ['nullable', 'in:day,month,quarter'],
            'consultant_id' => ['nullable', 'integer', 'exists:users,id'],
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
            ->join('admissions as prev', $this->readmissionJoin($readmitWindow))
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
        // restored legacy grid columns: ICU transfers + ICU/ward death split + per-bucket readmissions
        $icuTransBy = $this->seriesBy('admissions', 'discharge_date', $f, $t, $interval, "discharge_to = 'Intensive Care (ICU)'");
        $icuDeathBy = $this->seriesBy('admissions', 'discharge_date', $f, $t, $interval, "outcome = 'Dead' AND current_location = 'ICU'");
        $wardDeathBy = $this->seriesBy('admissions', 'discharge_date', $f, $t, $interval, "outcome = 'Dead' AND {$this->nonIcu}");
        $readmitBy = DB::table('admissions as a')
            ->join('admissions as prev', $this->readmissionJoin($readmitWindow))
            ->whereBetween('a.admit_date', [$f, $t])
            ->selectRaw($this->keyExpr('a.admit_date', $interval) . ' k, COUNT(DISTINCT a.id) c')
            ->groupBy('k')->pluck('c', 'k')->all();
        $losBy = DB::table('admissions')->whereBetween('discharge_date', [$f, $t])->whereNotNull('admit_date')
            ->whereRaw($this->nonIcu)->whereRaw('DATEDIFF(discharge_date, admit_date) >= 0')
            ->selectRaw($this->keyExpr('discharge_date', $interval) . ' k, ROUND(AVG(DATEDIFF(discharge_date, admit_date)), 1) los')
            ->groupBy('k')->pluck('los', 'k')->all();

        // day-interval bucket list is capped (~370) — surface it so the page can suggest Monthly
        $truncated = $interval === 'day' && $buckets !== [] && end($buckets)['key'] < $t;

        $monthly = [
            'labels' => array_column($buckets, 'label'),
            'keys' => $keys,   // raw bucket keys (Y-m-d for day interval) — drive Fri/Sat tick coloring
            'admissions' => array_map(fn ($k) => (int) ($admBy[$k] ?? 0), $keys),
            'discharges' => array_map(fn ($k) => (int) ($disBy[$k] ?? 0), $keys),
            'deaths' => array_map(fn ($k) => (int) ($deathBy[$k] ?? 0), $keys),
            'consultations' => array_map(fn ($k) => (int) ($consBy[$k] ?? 0), $keys),
            'signoffs' => array_map(fn ($k) => (int) ($signBy[$k] ?? 0), $keys),
        ];
        // grid splits deaths into ICU/ward (legacy KPI grid) — the headline 'deaths' KPI and the
        // monthly chart keep the single reconciled all-locations figure above
        $kpiGrid = array_map(fn ($b) => [
            'label' => $b['label'],
            'admissions' => (int) ($admBy[$b['key']] ?? 0), 'discharges' => (int) ($disBy[$b['key']] ?? 0),
            'icu' => (int) ($icuBy[$b['key']] ?? 0), 'transToIcu' => (int) ($icuTransBy[$b['key']] ?? 0),
            'icuDeaths' => (int) ($icuDeathBy[$b['key']] ?? 0), 'wardDeaths' => (int) ($wardDeathBy[$b['key']] ?? 0),
            'readmits' => (int) ($readmitBy[$b['key']] ?? 0),
            'consultations' => (int) ($consBy[$b['key']] ?? 0), 'signoffs' => (int) ($signBy[$b['key']] ?? 0),
            'avgLos' => (float) ($losBy[$b['key']] ?? 0),
        ], $buckets);

        // discharge destinations (range) for a donut — overall uses the SHARED legacy 7-bucket
        // split over non-ICU closed episodes (Admission::bucketizeDestinations, same as Reports);
        // the per-consultant variant below stays raw values
        $destBuckets = Admission::bucketizeDestinations(
            DB::table('admissions')->whereBetween('discharge_date', [$f, $t])->whereRaw($this->nonIcu)
                ->selectRaw("COALESCE(discharge_to, '') dst, COUNT(*) c")
                ->groupBy('dst')->pluck('c', 'dst')->all());

        $byCons = [];
        DB::table('admissions as a')->join('users as u', 'u.id', '=', 'a.consultant_id')
            ->whereBetween('a.discharge_date', [$f, $t])
            // 'consultant' alias avoids the users.name column collision (MySQL groups by the column
            // under only_full_group_by; MariaDB can't match repeated raw expressions) — see Dashboard
            ->selectRaw("COALESCE(u.full_name, u.name) consultant, COALESCE(NULLIF(TRIM(a.discharge_to), ''), 'Unspecified') dest, COUNT(*) c")
            ->groupBy('consultant', 'dest')
            ->get()->each(function ($r) use (&$byCons) { $byCons[$r->consultant][$r->dest] = (int) $r->c; });
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

        // per-consultant KPI modes (range, ALL consultants): admissions, avg LOS (non-ICU,
        // discharge-based like the headline) and window readmissions — one grouped query per
        // metric (the 'consultant' alias avoids the users.name collision; see $byCons above)
        $admByCons = DB::table('admissions as a')->join('users as u', 'u.id', '=', 'a.consultant_id')
            ->whereBetween('a.admit_date', [$f, $t])
            ->selectRaw('COALESCE(u.full_name, u.name) consultant, COUNT(*) c')
            ->groupBy('consultant')->pluck('c', 'consultant')->all();
        $losByCons = DB::table('admissions as a')->join('users as u', 'u.id', '=', 'a.consultant_id')
            ->whereBetween('a.discharge_date', [$f, $t])->whereNotNull('a.admit_date')
            ->whereRaw($this->nonIcu)->whereRaw('DATEDIFF(a.discharge_date, a.admit_date) >= 0')
            ->selectRaw('COALESCE(u.full_name, u.name) consultant, ROUND(AVG(DATEDIFF(a.discharge_date, a.admit_date)), 1) los')
            ->groupBy('consultant')->pluck('los', 'consultant')->all();
        $readmitByCons = DB::table('admissions as a')
            ->join('admissions as prev', $this->readmissionJoin($readmitWindow))
            ->join('users as u', 'u.id', '=', 'a.consultant_id')
            ->whereBetween('a.admit_date', [$f, $t])
            ->selectRaw('COALESCE(u.full_name, u.name) consultant, COUNT(DISTINCT a.id) c')
            ->groupBy('consultant')->pluck('c', 'consultant')->all();
        $perConsultant = collect(array_keys($admByCons))
            ->merge(array_keys($losByCons))->merge(array_keys($readmitByCons))->unique()
            ->map(fn ($name) => [
                'name' => $name,
                'admissions' => (int) ($admByCons[$name] ?? 0),
                'avgLos' => (float) ($losByCons[$name] ?? 0),
                'readmits' => (int) ($readmitByCons[$name] ?? 0),
            ])->sortByDesc('admissions')->values();

        // admission source mix
        $sourceMix = DB::table('admissions')->whereBetween('admit_date', [$f, $t])
            ->selectRaw("COALESCE(NULLIF(admitted_from, ''), 'Unknown') src, COUNT(*) c")
            ->groupBy('src')->orderByDesc('c')->limit(6)->get();

        $physician = empty($data['consultant_id'])
            ? null
            : $this->physician((int) $data['consultant_id'], $f, $t, $readmitWindow);

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
            'truncated' => $truncated,
            'readmitWindow' => $readmitWindow,
            'destinations' => ['labels' => array_keys($destBuckets), 'data' => array_values($destBuckets)],
            'destByConsultant' => $destByConsultant,
            'consultants' => \App\Models\User::consultantOptions(),
            'physician' => $physician,
        ]);
    }

    /**
     * Per-physician drill-down: the legacy charts.php view — destination buckets over CLOSED
     * episodes, top-5 diagnoses, and the headline KPI formulas scoped to one consultant.
     */
    private function physician(int $consultantId, string $f, string $t, int $readmitWindow): array
    {
        $u = \App\Models\User::findOrFail($consultantId);

        // legacy transfer-type buckets (charts.php): fixed order, zero-filled
        $destMap = [
            'discharge from ward' => 'Discharged',
            'transfer to other speciality' => 'Intra-dept transfer',
            'other transfer' => 'Out-dept transfer',
            'discharge from ICU' => 'ICU discharge',
        ];
        $byType = DB::table('admissions')->where('consultant_id', $consultantId)
            ->whereBetween('discharge_date', [$f, $t])->whereIn('transfer_type', array_keys($destMap))
            ->selectRaw('transfer_type tt, COUNT(*) c')->groupBy('tt')->pluck('c', 'tt')->all();

        $topDx = DB::table('admission_diagnoses as ad')
            ->join('admissions as a', 'a.id', '=', 'ad.admission_id')
            ->leftJoin('icd10 as i', 'i.code', '=', 'ad.icd10_code')
            ->where('a.consultant_id', $consultantId)->whereBetween('a.admit_date', [$f, $t])
            ->selectRaw('ad.icd10_code code, MAX(i.name) name, COUNT(*) c')
            ->groupBy('ad.icd10_code')->orderByDesc('c')->limit(5)->get()
            ->map(fn ($r) => ['label' => $r->name ?: $r->code, 'value' => (int) $r->c]);

        $scoped = fn () => DB::table('admissions')->where('consultant_id', $consultantId);

        return [
            'id' => $consultantId,
            'name' => $u->full_name ?: $u->name,
            'destinations' => ['labels' => array_values($destMap),
                'data' => array_map(fn ($type) => (int) ($byType[$type] ?? 0), array_keys($destMap))],
            'topDx' => $topDx,
            // the reconciled headline formulas, scoped by consultant_id
            'numbers' => [
                'admissions' => (int) $scoped()->whereBetween('admit_date', [$f, $t])->whereRaw($this->nonIcu)->count(),
                'discharges' => (int) $scoped()->whereBetween('discharge_date', [$f, $t])->whereRaw($this->nonIcu)->count(),
                'deaths' => (int) $scoped()->where('outcome', 'Dead')->whereBetween('discharge_date', [$f, $t])->count(),
                'avgLos' => round((float) ($scoped()->whereBetween('discharge_date', [$f, $t])->whereNotNull('admit_date')
                    ->whereRaw($this->nonIcu)->whereRaw('DATEDIFF(discharge_date, admit_date) >= 0')
                    ->selectRaw('AVG(DATEDIFF(discharge_date, admit_date)) v')->value('v') ?? 0), 1),
                'readmissions' => (int) DB::table('admissions as a')
                    ->join('admissions as prev', $this->readmissionJoin($readmitWindow))
                    ->where('a.consultant_id', $consultantId)->whereBetween('a.admit_date', [$f, $t])
                    ->distinct()->count('a.id'),
            ],
        ];
    }

    /**
     * The ONE readmission JOIN predicate — now centralised on the Admission model so Reports
     * uses the identical definition (see Admission::readmissionJoin and the headline comment above).
     */
    private function readmissionJoin(int $window): \Closure
    {
        return \App\Models\Admission::readmissionJoin($window);
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
