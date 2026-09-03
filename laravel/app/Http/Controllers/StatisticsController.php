<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\MetricQueries;
use App\Http\Requests\StatisticsExportRequest;
use App\Models\Admission;
use App\Models\ConsultationReason;
use App\Models\Setting;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Unit statistics over a selectable date range. Queries are sargable: date filters use range
 * predicates on the indexed admit_date / discharge_date columns (no MONTH()/YEAR() wrapping that
 * would defeat the index — a defect called out in the legacy review). Definitions follow docs/METRICS.md.
 */
class StatisticsController extends Controller
{
    use MetricQueries;

    private string $nonIcu = Admission::NON_ICU_SQL;

    public function index(Request $request): Response
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'interval' => ['nullable', 'in:day,month,quarter'],
            'consultant_id' => ['nullable', 'integer', 'exists:users,id'],
            // Phase 3 — §3.5: year-over-year / prior-period overlay (null when absent)
            'compare' => ['nullable', 'in:prior_year,prior_period'],
        ]);
        $to = isset($data['to']) ? Carbon::parse($data['to']) : Carbon::today();
        $from = isset($data['from']) ? Carbon::parse($data['from']) : $to->copy()->startOfYear();
        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }
        $f = $from->toDateString();
        $t = $to->toDateString();
        $interval = $data['interval'] ?? 'month';

        // headline KPIs
        // Phase 4 — Item 1: every raw admissions/consultations analytic excludes soft-deleted rows
        // (whereNull(deleted_at)); the seriesBy() helper does it centrally for the time-series.
        $admissions = (int) DB::table('admissions')->whereBetween('admit_date', [$f, $t])->whereRaw($this->nonIcu)->whereNull('deleted_at')->count();
        $discharges = (int) DB::table('admissions')->whereBetween('discharge_date', [$f, $t])->whereRaw($this->nonIcu)->whereNull('deleted_at')->count();
        $deaths = (int) DB::table('admissions')->where('outcome', 'Dead')->whereBetween('discharge_date', [$f, $t])->whereNull('deleted_at')->count();
        $icuAdmissions = (int) DB::table('admissions')->whereBetween('admit_date', [$f, $t])->where('current_location', 'ICU')->whereNull('deleted_at')->count();
        $consultations = (int) DB::table('consultations')->whereBetween('consultation_date', [$f, $t])->whereNull('deleted_at')->count();
        $signoffs = (int) DB::table('consultations')->whereBetween('signoff_date', [$f, $t])->whereNull('deleted_at')->count();

        $losAgg = DB::table('admissions')
            ->whereBetween('discharge_date', [$f, $t])->whereNotNull('admit_date')->whereRaw($this->nonIcu)->whereNull('deleted_at')
            ->whereRaw('DATEDIFF(discharge_date, admit_date) >= 0')
            ->selectRaw('AVG(DATEDIFF(discharge_date, admit_date)) avg_los, COUNT(*) n')->first();
        $avgLos = round((float) ($losAgg->avg_los ?? 0), 1);
        $mortalityRate = $discharges > 0 ? round($deaths / $discharges * 100, 1) : 0.0;

        // readmissions: a new admission within the CONFIGURED window of a prior REAL discharge for
        // the same patient (settings.readmission_window_days; clinically confirmed default 3 = the
        // "72-hour" rule). The prior episode must have ended in an actual discharge ('discharge from
        // ward/ICU') — ward<->ICU and specialty transfers are continuations of care, not discharges,
        // so they are excluded (otherwise same-day transfer rows inflate the metric).
        $readmitWindow = max(0, (int) (Setting::current()->readmission_window_days ?? 3));
        $readmissions = (int) DB::table('admissions as a')
            ->join('admissions as prev', $this->readmissionJoin($readmitWindow))
            ->whereBetween('a.admit_date', [$f, $t])
            ->whereNull('a.deleted_at')->whereNull('prev.deleted_at')   // Phase 4 — Item 1: both join sides
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
            ->whereNull('a.deleted_at')->whereNull('prev.deleted_at')   // Phase 4 — Item 1
            ->selectRaw($this->keyExpr('a.admit_date', $interval).' k, COUNT(DISTINCT a.id) c')
            ->groupBy('k')->pluck('c', 'k')->all();
        $losBy = DB::table('admissions')->whereBetween('discharge_date', [$f, $t])->whereNotNull('admit_date')
            ->whereRaw($this->nonIcu)->whereNull('deleted_at')->whereRaw('DATEDIFF(discharge_date, admit_date) >= 0')
            ->selectRaw($this->keyExpr('discharge_date', $interval).' k, ROUND(AVG(DATEDIFF(discharge_date, admit_date)), 1) los')
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
        // monthly chart keep the single reconciled all-locations figure above. Extracted to
        // buildKpiGrid so /statistics/export emits the SAME rows (the §3.8 "can't drift" contract).
        $kpiGrid = $this->buildKpiGrid($buckets, $admBy, $disBy, $icuBy, $icuTransBy, $icuDeathBy, $wardDeathBy, $readmitBy, $consBy, $signBy, $losBy);

        // discharge destinations (range) for a donut — overall uses the SHARED legacy 7-bucket
        // split over non-ICU closed episodes (Admission::bucketizeDestinations, same as Reports);
        // the per-consultant variant below stays raw values
        $destBuckets = Admission::bucketizeDestinations(
            DB::table('admissions')->whereBetween('discharge_date', [$f, $t])->whereRaw($this->nonIcu)->whereNull('deleted_at')
                ->selectRaw("COALESCE(discharge_to, '') dst, COUNT(*) c")
                ->groupBy('dst')->pluck('c', 'dst')->all());

        $byCons = [];
        DB::table('admissions as a')->join('users as u', 'u.id', '=', 'a.consultant_id')
            ->whereBetween('a.discharge_date', [$f, $t])->whereNull('a.deleted_at')
            // 'consultant' alias avoids the users.name column collision (MySQL groups by the column
            // under only_full_group_by; MariaDB can't match repeated raw expressions) — see Dashboard
            ->selectRaw("COALESCE(u.full_name, u.name) consultant, COALESCE(NULLIF(TRIM(a.discharge_to), ''), 'Unspecified') dest, COUNT(*) c")
            ->groupBy('consultant', 'dest')
            ->get()->each(function ($r) use (&$byCons) {
                $byCons[$r->consultant][$r->dest] = (int) $r->c;
            });
        uasort($byCons, fn ($a, $b) => array_sum($b) <=> array_sum($a));
        $destByConsultant = [];
        foreach (array_slice($byCons, 0, 12, true) as $name => $dests) {
            arsort($dests);
            $destByConsultant[] = ['name' => $name, 'labels' => array_keys($dests), 'data' => array_values($dests)];
        }

        // LOS distribution
        $losRows = DB::table('admissions')->selectRaw('DATEDIFF(discharge_date, admit_date) los')
            ->whereBetween('discharge_date', [$f, $t])->whereNotNull('admit_date')->whereRaw($this->nonIcu)->whereNull('deleted_at')
            ->whereRaw('DATEDIFF(discharge_date, admit_date) >= 0')->pluck('los');
        $losBuckets = ['0–2' => 0, '3–5' => 0, '6–10' => 0, '11–20' => 0, '21+' => 0];
        foreach ($losRows as $l) {
            $l = (int) $l;
            if ($l <= 2) {
                $losBuckets['0–2']++;
            } elseif ($l <= 5) {
                $losBuckets['3–5']++;
            } elseif ($l <= 10) {
                $losBuckets['6–10']++;
            } elseif ($l <= 20) {
                $losBuckets['11–20']++;
            } else {
                $losBuckets['21+']++;
            }
        }

        // top diagnoses (range)
        $topDx = DB::table('admission_diagnoses as ad')
            ->join('admissions as a', 'a.id', '=', 'ad.admission_id')
            ->leftJoin('icd10 as i', 'i.code', '=', 'ad.icd10_code')
            ->whereBetween('a.admit_date', [$f, $t])->whereNull('a.deleted_at')   // Phase 4 — Item 1
            ->selectRaw('ad.icd10_code code, MAX(i.name) name, COUNT(*) c')
            ->groupBy('ad.icd10_code')->orderByDesc('c')->limit(8)->get()
            ->map(fn ($r) => ['label' => $r->name ? mb_strimwidth($r->name, 0, 32, '…') : $r->code, 'value' => (int) $r->c]);

        // consultations by reason (decode JSON indication in PHP — small set)
        $reasonNames = ConsultationReason::pluck('name', 'id');
        $reasonTally = [];
        DB::table('consultations')->whereBetween('consultation_date', [$f, $t])->whereNull('deleted_at')->select('indication')
            ->orderBy('id')->chunk(500, function ($chunk) use (&$reasonTally, $reasonNames) {
                foreach ($chunk as $c) {
                    foreach (json_decode($c->indication ?? '', true) ?: [] as $id) {
                        $name = $reasonNames[$id] ?? null;
                        if ($name) {
                            $reasonTally[$name] = ($reasonTally[$name] ?? 0) + 1;
                        }
                    }
                }
            });
        arsort($reasonTally);
        $reasonTally = array_slice($reasonTally, 0, 8, true);

        // per-consultant KPI modes (range, ALL consultants): admissions + discharges are NON-ICU
        // like the legacy per-physician stats and the headline KPIs (J1-12); avg LOS (non-ICU,
        // discharge-based like the headline) and window readmissions — one grouped query per
        // metric (the 'consultant' alias avoids the users.name collision; see $byCons above)
        $admByCons = DB::table('admissions as a')->join('users as u', 'u.id', '=', 'a.consultant_id')
            ->whereBetween('a.admit_date', [$f, $t])->whereRaw($this->nonIcu)->whereNull('a.deleted_at')
            ->selectRaw('COALESCE(u.full_name, u.name) consultant, COUNT(*) c')
            ->groupBy('consultant')->pluck('c', 'consultant')->all();
        $losByCons = DB::table('admissions as a')->join('users as u', 'u.id', '=', 'a.consultant_id')
            ->whereBetween('a.discharge_date', [$f, $t])->whereNotNull('a.admit_date')->whereNull('a.deleted_at')
            ->whereRaw($this->nonIcu)->whereRaw('DATEDIFF(a.discharge_date, a.admit_date) >= 0')
            ->selectRaw('COALESCE(u.full_name, u.name) consultant, ROUND(AVG(DATEDIFF(a.discharge_date, a.admit_date)), 1) los')
            ->groupBy('consultant')->pluck('los', 'consultant')->all();
        // readmissions are credited to the PRIOR discharge's consultant (prev.consultant_id) —
        // legacy semantics: the metric reflects whose discharge bounced back (J1-13). A readmission
        // can match SEVERAL qualifying prior discharges (different consultants); legacy charts1.php
        // credited exactly ONE (LIMIT 1). We credit a SINGLE anchor per readmitted admission — the
        // most-recent qualifying prior discharge (tie-break highest id) — so the per-consultant
        // column SUMS to the headline distinct count instead of over-counting (N1-8).
        $readmitByCons = DB::table('admissions as a')
            ->join('admissions as prev', $this->readmissionJoin($readmitWindow))
            ->join('users as u', 'u.id', '=', 'prev.consultant_id')
            ->whereBetween('a.admit_date', [$f, $t])
            ->whereNull('a.deleted_at')->whereNull('prev.deleted_at')   // Phase 4 — Item 1
            // keep only the ANCHOR pair per readmitted admission: no OTHER qualifying prior discharge
            // for the same admission is more recent (or same date with a higher id)
            ->whereNotExists(function ($q) use ($readmitWindow) {
                $q->selectRaw('1')->from('admissions as prev2')
                    ->whereColumn('prev2.patient_id', '=', 'a.patient_id')
                    ->whereColumn('prev2.discharge_date', '<=', 'a.admit_date')
                    ->whereRaw('DATEDIFF(a.admit_date, prev2.discharge_date) BETWEEN 0 AND ?', [$readmitWindow])
                    ->whereColumn('prev2.id', '<>', 'a.id')
                    ->whereColumn('prev2.id', '<>', 'prev.id')
                    ->whereNull('prev2.deleted_at')   // Phase 4 — Item 1: a soft-deleted prior episode is not a more-recent anchor
                    ->where(fn ($w) => $w->whereIn('prev2.transfer_type', Admission::REAL_DISCHARGE_TYPES)
                        ->orWhereNull('prev2.transfer_type'))
                    ->where(fn ($w) => $w->whereColumn('prev2.discharge_date', '>', 'prev.discharge_date')
                        ->orWhere(fn ($e) => $e->whereColumn('prev2.discharge_date', '=', 'prev.discharge_date')
                            ->whereColumn('prev2.id', '>', 'prev.id')));
            })
            ->selectRaw('COALESCE(u.full_name, u.name) consultant, COUNT(DISTINCT a.id) c')
            ->groupBy('consultant')->pluck('c', 'consultant')->all();
        // 'activity' mode values: discharges + consultations + sign-offs per consultant over the
        // range (admissions reuses $admByCons) — one grouped query each, same 'consultant' alias;
        // discharges are NON-ICU like the headline (J1-12)
        $disByCons = DB::table('admissions as a')->join('users as u', 'u.id', '=', 'a.consultant_id')
            ->whereBetween('a.discharge_date', [$f, $t])->whereRaw($this->nonIcu)->whereNull('a.deleted_at')
            ->selectRaw('COALESCE(u.full_name, u.name) consultant, COUNT(*) c')
            ->groupBy('consultant')->pluck('c', 'consultant')->all();
        $consByCons = DB::table('consultations as co')->join('users as u', 'u.id', '=', 'co.consultant_id')
            ->whereBetween('co.consultation_date', [$f, $t])->whereNull('co.deleted_at')
            ->selectRaw('COALESCE(u.full_name, u.name) consultant, COUNT(*) c')
            ->groupBy('consultant')->pluck('c', 'consultant')->all();
        $signByCons = DB::table('consultations as co')->join('users as u', 'u.id', '=', 'co.consultant_id')
            ->whereBetween('co.signoff_date', [$f, $t])->whereNull('co.deleted_at')
            ->selectRaw('COALESCE(u.full_name, u.name) consultant, COUNT(*) c')
            ->groupBy('consultant')->pluck('c', 'consultant')->all();
        // full ACTIVE-consultant roster with zeros (J2-3): a consultant with no activity in the
        // range still gets a bar, like the legacy per-physician tables built from the members list
        $rosterNames = User::where('role', User::ROLE_CONSULTANT)->where('active', 1)
            ->get(['full_name', 'name'])->map(fn ($u) => $u->full_name ?: $u->name);
        $perConsultant = collect(array_keys($admByCons))
            ->merge(array_keys($losByCons))->merge(array_keys($readmitByCons))
            ->merge(array_keys($disByCons))->merge(array_keys($consByCons))->merge(array_keys($signByCons))
            ->merge($rosterNames)
            ->unique()
            ->map(fn ($name) => [
                'name' => $name,
                'admissions' => (int) ($admByCons[$name] ?? 0),
                'avgLos' => (float) ($losByCons[$name] ?? 0),
                'readmits' => (int) ($readmitByCons[$name] ?? 0),
                'discharges' => (int) ($disByCons[$name] ?? 0),
                'consultations' => (int) ($consByCons[$name] ?? 0),
                'signoffs' => (int) ($signByCons[$name] ?? 0),
            ])->sortByDesc('admissions')->values();

        // admission source mix
        $sourceMix = DB::table('admissions')->whereBetween('admit_date', [$f, $t])->whereNull('deleted_at')
            ->selectRaw("COALESCE(NULLIF(admitted_from, ''), 'Unknown') src, COUNT(*) c")
            ->groupBy('src')->orderByDesc('c')->limit(6)->get();

        $physician = empty($data['consultant_id'])
            ? null
            : $this->physician((int) $data['consultant_id'], $f, $t, $readmitWindow, $interval, $buckets);

        // Phase 3 — §3.5: optional year-over-year / prior-period overlay. Reuses the SAME
        // seriesBy / nonIcu helpers as the primary period, so the comparison series cannot drift.
        $compareData = $this->compareBlock($data['compare'] ?? null, $from, $to, $interval);

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
            // drill-down picker spans INACTIVE consultants too (K1-8): departed staff stay
            // queryable over historical ranges, like the registry filter
            'consultants' => User::consultantOptions(activeOnly: false),
            'physician' => $physician,
            'compareData' => $compareData,
        ]);
    }

    /**
     * Phase 3 — §3.4: the KPI-grid row map, extracted so /statistics/export emits byte-identical
     * rows (the §3.8 "can't drift" contract — export and screen share this ONE construction).
     */
    private function buildKpiGrid(array $buckets, array $admBy, array $disBy, array $icuBy, array $icuTransBy, array $icuDeathBy, array $wardDeathBy, array $readmitBy, array $consBy, array $signBy, array $losBy): array
    {
        return array_map(fn ($b) => [
            'label' => $b['label'],
            'admissions' => (int) ($admBy[$b['key']] ?? 0), 'discharges' => (int) ($disBy[$b['key']] ?? 0),
            'icu' => (int) ($icuBy[$b['key']] ?? 0), 'transToIcu' => (int) ($icuTransBy[$b['key']] ?? 0),
            'icuDeaths' => (int) ($icuDeathBy[$b['key']] ?? 0), 'wardDeaths' => (int) ($wardDeathBy[$b['key']] ?? 0),
            'readmits' => (int) ($readmitBy[$b['key']] ?? 0),
            'consultations' => (int) ($consBy[$b['key']] ?? 0), 'signoffs' => (int) ($signBy[$b['key']] ?? 0),
            'avgLos' => (float) ($losBy[$b['key']] ?? 0),
        ], $buckets);
    }

    /**
     * Phase 3 — §3.5: the comparison data block (null when $compare is absent). Derives the
     * comparison range (same calendar dates a year earlier, or the immediately-preceding
     * equal-length window) and rebuilds the 3 chart series + 5 headline KPIs through the SAME
     * seriesBy / nonIcu helpers used for the primary period — guarded by YoyComparisonTest.
     */
    private function compareBlock(?string $compare, Carbon $from, Carbon $to, string $interval): ?array
    {
        if (! $compare) {
            return null;
        }

        $span = $from->diffInDays($to);
        if ($compare === 'prior_year') {
            $cf = $from->copy()->subYear()->toDateString();
            $ct = $to->copy()->subYear()->toDateString();
        } else { // prior_period: equal-length window immediately before $from
            $cf = $from->copy()->subDays($span + 1)->toDateString();
            $ct = $from->copy()->subDay()->toDateString();
        }

        $cBuckets = $this->buckets(Carbon::parse($cf), Carbon::parse($ct), $interval);
        $cKeys = array_column($cBuckets, 'key');
        $cAdm = $this->seriesBy('admissions', 'admit_date', $cf, $ct, $interval, $this->nonIcu);
        $cDis = $this->seriesBy('admissions', 'discharge_date', $cf, $ct, $interval, $this->nonIcu);
        $cDeath = $this->seriesBy('admissions', 'discharge_date', $cf, $ct, $interval, "outcome = 'Dead'");

        $cAdmissions = (int) DB::table('admissions')->whereBetween('admit_date', [$cf, $ct])->whereRaw($this->nonIcu)->whereNull('deleted_at')->count();
        $cDischarges = (int) DB::table('admissions')->whereBetween('discharge_date', [$cf, $ct])->whereRaw($this->nonIcu)->whereNull('deleted_at')->count();
        $cDeaths = (int) DB::table('admissions')->where('outcome', 'Dead')->whereBetween('discharge_date', [$cf, $ct])->whereNull('deleted_at')->count();
        $cAvgLos = round((float) (DB::table('admissions')->whereBetween('discharge_date', [$cf, $ct])->whereNotNull('admit_date')
            ->whereRaw($this->nonIcu)->whereNull('deleted_at')->whereRaw('DATEDIFF(discharge_date, admit_date) >= 0')
            ->avg(DB::raw('DATEDIFF(discharge_date, admit_date)')) ?? 0), 1);

        return [
            'labels' => array_column($cBuckets, 'label'),
            'admissions' => array_map(fn ($k) => (int) ($cAdm[$k] ?? 0), $cKeys),
            'discharges' => array_map(fn ($k) => (int) ($cDis[$k] ?? 0), $cKeys),
            'deaths' => array_map(fn ($k) => (int) ($cDeath[$k] ?? 0), $cKeys),
            'kpis' => [
                'admissions' => $cAdmissions,
                'discharges' => $cDischarges,
                'deaths' => $cDeaths,
                'mortalityRate' => $cDischarges > 0 ? round($cDeaths / $cDischarges * 100, 1) : 0.0,
                'avgLos' => $cAvgLos,
            ],
            'range' => ['from' => $cf, 'to' => $ct],
        ];
    }

    /**
     * Per-physician drill-down: the legacy charts.php view — destination buckets over CLOSED
     * episodes, top-5 diagnoses, the headline KPI formulas scoped to one consultant, and a
     * bucketed 4-series (admissions/discharges/consultations/sign-offs) over the range.
     */
    public function physician(int $consultantId, string $f, string $t, int $readmitWindow, string $interval, array $buckets): array
    {
        $u = User::findOrFail($consultantId);

        // bucketed activity time-series, scoped to this consultant (reuses the page's
        // interval buckets; $consultantId is validated+cast, safe in the raw predicate).
        // admissions/discharges are NON-ICU like legacy and the scoped numbers below (J1-12);
        // consultations/sign-offs are never location-split.
        $keys = array_column($buckets, 'key');
        $own = "consultant_id = {$consultantId}";
        $admS = $this->seriesBy('admissions', 'admit_date', $f, $t, $interval, "{$own} AND {$this->nonIcu}");
        $disS = $this->seriesBy('admissions', 'discharge_date', $f, $t, $interval, "{$own} AND {$this->nonIcu}");
        $consS = $this->seriesBy('consultations', 'consultation_date', $f, $t, $interval, $own);
        $signS = $this->seriesBy('consultations', 'signoff_date', $f, $t, $interval, $own);

        // legacy transfer-type buckets (charts.php): fixed order, zero-filled, NON-ICU like the
        // legacy GROUP BY trans_discharge query (charts.php:57) — K1-6
        $destMap = [
            'discharge from ward' => 'Discharged',
            'transfer to other speciality' => 'Intra-dept transfer',
            'other transfer' => 'Out-dept transfer',
            'discharge from ICU' => 'ICU discharge',
        ];
        $byType = DB::table('admissions')->where('consultant_id', $consultantId)
            ->whereBetween('discharge_date', [$f, $t])->whereIn('transfer_type', array_keys($destMap))
            ->whereRaw($this->nonIcu)->whereNull('deleted_at')
            ->selectRaw('transfer_type tt, COUNT(*) c')->groupBy('tt')->pluck('c', 'tt')->all();

        // top-5 diagnoses over NON-ICU admissions, like legacy charts.php:922 — K1-6
        $topDx = DB::table('admission_diagnoses as ad')
            ->join('admissions as a', 'a.id', '=', 'ad.admission_id')
            ->leftJoin('icd10 as i', 'i.code', '=', 'ad.icd10_code')
            ->where('a.consultant_id', $consultantId)->whereBetween('a.admit_date', [$f, $t])
            ->whereRaw($this->nonIcu)->whereNull('a.deleted_at')
            ->selectRaw('ad.icd10_code code, MAX(i.name) name, COUNT(*) c')
            ->groupBy('ad.icd10_code')->orderByDesc('c')->limit(5)->get()
            ->map(fn ($r) => ['label' => $r->name ?: $r->code, 'value' => (int) $r->c]);

        // Phase 4 — Item 1: the scoped closures exclude soft-deleted rows for every reuse below
        $scoped = fn () => DB::table('admissions')->where('consultant_id', $consultantId)->whereNull('deleted_at');
        $consScoped = fn () => DB::table('consultations')->where('consultant_id', $consultantId)->whereNull('deleted_at');

        // second donut (J2-4): legacy charts.php 'Discharged to' — the fixed 6 buckets over this
        // consultant's NON-ICU closed episodes; anything outside the 5 named ones => 'Transfer'
        $named = ['Home', 'Other Facility', 'LAMA', 'Absconded', 'Mortuary'];
        $byDest = $scoped()->whereBetween('discharge_date', [$f, $t])->whereRaw($this->nonIcu)
            ->selectRaw("COALESCE(discharge_to, '') dst, COUNT(*) c")->groupBy('dst')->pluck('c', 'dst')->all();
        $dischargedTo = array_fill_keys([...$named, 'Transfer'], 0);
        foreach ($byDest as $dst => $c) {
            $dischargedTo[in_array($dst, $named, true) ? $dst : 'Transfer'] += (int) $c;
        }

        return [
            'id' => $consultantId,
            'name' => $u->full_name ?: $u->name,
            'destinations' => ['labels' => array_values($destMap),
                'data' => array_map(fn ($type) => (int) ($byType[$type] ?? 0), array_keys($destMap))],
            'dischargedTo' => ['labels' => array_keys($dischargedTo), 'data' => array_values($dischargedTo)],
            'topDx' => $topDx,
            // the reconciled headline formulas, scoped by consultant_id
            'numbers' => [
                'admissions' => (int) $scoped()->whereBetween('admit_date', [$f, $t])->whereRaw($this->nonIcu)->count(),
                'discharges' => (int) $scoped()->whereBetween('discharge_date', [$f, $t])->whereRaw($this->nonIcu)->count(),
                // legacy per-physician extras (J2-4): transfers to ICU + consultation activity
                'transToIcu' => (int) $scoped()->whereBetween('discharge_date', [$f, $t])
                    ->where('discharge_to', 'Intensive Care (ICU)')->count(),
                'deaths' => (int) $scoped()->where('outcome', 'Dead')->whereBetween('discharge_date', [$f, $t])->count(),
                // 2 decimals like the legacy per-physician LOS figure (J2-6)
                'avgLos' => round((float) ($scoped()->whereBetween('discharge_date', [$f, $t])->whereNotNull('admit_date')
                    ->whereRaw($this->nonIcu)->whereRaw('DATEDIFF(discharge_date, admit_date) >= 0')
                    ->selectRaw('AVG(DATEDIFF(discharge_date, admit_date)) v')->value('v') ?? 0), 2),
                // legacy charts.php:1199 counted a readmission only when the PRIOR discharge was
                // ALSO this consultant's ($id == $recentadmission['consultant_id']) — K1-7
                'readmissions' => (int) DB::table('admissions as a')
                    ->join('admissions as prev', $this->readmissionJoin($readmitWindow))
                    ->where('a.consultant_id', $consultantId)->where('prev.consultant_id', $consultantId)
                    ->whereBetween('a.admit_date', [$f, $t])
                    ->whereNull('a.deleted_at')->whereNull('prev.deleted_at')   // Phase 4 — Item 1
                    ->distinct()->count('a.id'),
                'consultations' => (int) $consScoped()->whereBetween('consultation_date', [$f, $t])->count(),
                'signoffs' => (int) $consScoped()->whereBetween('signoff_date', [$f, $t])->count(),
            ],
            'series' => [
                'labels' => array_column($buckets, 'label'),
                'admissions' => array_map(fn ($k) => (int) ($admS[$k] ?? 0), $keys),
                'discharges' => array_map(fn ($k) => (int) ($disS[$k] ?? 0), $keys),
                'consultations' => array_map(fn ($k) => (int) ($consS[$k] ?? 0), $keys),
                'signoffs' => array_map(fn ($k) => (int) ($signS[$k] ?? 0), $keys),
            ],
        ];
    }

    /**
     * The ONE readmission JOIN predicate — now centralised on the Admission model so Reports
     * uses the identical definition (see Admission::readmissionJoin and the headline comment above).
     */
    private function readmissionJoin(int $window): \Closure
    {
        return Admission::readmissionJoin($window);
    }

    /* ----------------------------- Phase 3 — §3.4 exports ----------------------------- */

    /**
     * Parse + clamp the export filter range identically to index() (the FormRequest already
     * validated the shapes). Returns [from Carbon, to Carbon, $f, $t, interval, readmitWindow].
     */
    private function exportRange(StatisticsExportRequest $request): array
    {
        $data = $request->validated();
        $to = isset($data['to']) ? Carbon::parse($data['to']) : Carbon::today();
        $from = isset($data['from']) ? Carbon::parse($data['from']) : $to->copy()->startOfYear();
        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }
        $interval = $data['interval'] ?? 'month';
        $window = max(0, (int) (Setting::current()->readmission_window_days ?? 3));

        return [$from, $to, $from->toDateString(), $to->toDateString(), $interval, $window];
    }

    /**
     * The three export tables (KPI grid, per-consultant, interval series). Routes the grid through
     * the SHARED buildKpiGrid + the SAME query methods as index() — never re-deriving a metric, so
     * the exported figures track the screen exactly (the §3.8 drift contract).
     */
    private function gatherExportTables(string $f, string $t, string $interval, int $window): array
    {
        $buckets = $this->buckets(Carbon::parse($f), Carbon::parse($t), $interval);
        $admBy = $this->seriesBy('admissions', 'admit_date', $f, $t, $interval, $this->nonIcu);
        $disBy = $this->seriesBy('admissions', 'discharge_date', $f, $t, $interval, $this->nonIcu);
        $icuBy = $this->seriesBy('admissions', 'admit_date', $f, $t, $interval, "current_location = 'ICU'");
        $icuTransBy = $this->seriesBy('admissions', 'discharge_date', $f, $t, $interval, "discharge_to = 'Intensive Care (ICU)'");
        $icuDeathBy = $this->seriesBy('admissions', 'discharge_date', $f, $t, $interval, "outcome = 'Dead' AND current_location = 'ICU'");
        $wardDeathBy = $this->seriesBy('admissions', 'discharge_date', $f, $t, $interval, "outcome = 'Dead' AND {$this->nonIcu}");
        $consBy = $this->seriesBy('consultations', 'consultation_date', $f, $t, $interval, '1=1');
        $signBy = $this->seriesBy('consultations', 'signoff_date', $f, $t, $interval, '1=1');
        $deathBy = $this->seriesBy('admissions', 'discharge_date', $f, $t, $interval, "outcome = 'Dead'");
        $readmitBy = DB::table('admissions as a')->join('admissions as prev', $this->readmissionJoin($window))
            ->whereBetween('a.admit_date', [$f, $t])
            ->whereNull('a.deleted_at')->whereNull('prev.deleted_at')   // Phase 4 — Item 1
            ->selectRaw($this->keyExpr('a.admit_date', $interval).' k, COUNT(DISTINCT a.id) c')
            ->groupBy('k')->pluck('c', 'k')->all();
        $losBy = DB::table('admissions')->whereBetween('discharge_date', [$f, $t])->whereNotNull('admit_date')
            ->whereRaw($this->nonIcu)->whereNull('deleted_at')->whereRaw('DATEDIFF(discharge_date, admit_date) >= 0')
            ->selectRaw($this->keyExpr('discharge_date', $interval).' k, ROUND(AVG(DATEDIFF(discharge_date, admit_date)), 1) los')
            ->groupBy('k')->pluck('los', 'k')->all();

        $kpiGrid = $this->buildKpiGrid($buckets, $admBy, $disBy, $icuBy, $icuTransBy, $icuDeathBy, $wardDeathBy, $readmitBy, $consBy, $signBy, $losBy);

        // interval series (Period | Adm | Disch | Deaths | Consults | Sign-offs)
        $series = array_map(fn ($b) => [
            'label' => $b['label'],
            'admissions' => (int) ($admBy[$b['key']] ?? 0), 'discharges' => (int) ($disBy[$b['key']] ?? 0),
            'deaths' => (int) ($deathBy[$b['key']] ?? 0),
            'consultations' => (int) ($consBy[$b['key']] ?? 0), 'signoffs' => (int) ($signBy[$b['key']] ?? 0),
        ], $buckets);

        // per-consultant table — the SAME grouped queries index() uses (admissions/LOS/readmits/
        // consultations/sign-offs over the range). Built compactly here, identical predicates.
        $admByCons = DB::table('admissions as a')->join('users as u', 'u.id', '=', 'a.consultant_id')
            ->whereBetween('a.admit_date', [$f, $t])->whereRaw($this->nonIcu)->whereNull('a.deleted_at')
            ->selectRaw('COALESCE(u.full_name, u.name) consultant, COUNT(*) c')->groupBy('consultant')->pluck('c', 'consultant')->all();
        $disByCons = DB::table('admissions as a')->join('users as u', 'u.id', '=', 'a.consultant_id')
            ->whereBetween('a.discharge_date', [$f, $t])->whereRaw($this->nonIcu)->whereNull('a.deleted_at')
            ->selectRaw('COALESCE(u.full_name, u.name) consultant, COUNT(*) c')->groupBy('consultant')->pluck('c', 'consultant')->all();
        $losByCons = DB::table('admissions as a')->join('users as u', 'u.id', '=', 'a.consultant_id')
            ->whereBetween('a.discharge_date', [$f, $t])->whereNotNull('a.admit_date')->whereNull('a.deleted_at')
            ->whereRaw($this->nonIcu)->whereRaw('DATEDIFF(a.discharge_date, a.admit_date) >= 0')
            ->selectRaw('COALESCE(u.full_name, u.name) consultant, ROUND(AVG(DATEDIFF(a.discharge_date, a.admit_date)), 1) los')
            ->groupBy('consultant')->pluck('los', 'consultant')->all();
        $consByCons = DB::table('consultations as co')->join('users as u', 'u.id', '=', 'co.consultant_id')
            ->whereBetween('co.consultation_date', [$f, $t])->whereNull('co.deleted_at')
            ->selectRaw('COALESCE(u.full_name, u.name) consultant, COUNT(*) c')->groupBy('consultant')->pluck('c', 'consultant')->all();
        $signByCons = DB::table('consultations as co')->join('users as u', 'u.id', '=', 'co.consultant_id')
            ->whereBetween('co.signoff_date', [$f, $t])->whereNull('co.deleted_at')
            ->selectRaw('COALESCE(u.full_name, u.name) consultant, COUNT(*) c')->groupBy('consultant')->pluck('c', 'consultant')->all();
        $readmitByCons = DB::table('admissions as a')->join('admissions as prev', $this->readmissionJoin($window))
            ->join('users as u', 'u.id', '=', 'prev.consultant_id')->whereBetween('a.admit_date', [$f, $t])
            ->whereNull('a.deleted_at')->whereNull('prev.deleted_at')   // Phase 4 — Item 1
            ->selectRaw('COALESCE(u.full_name, u.name) consultant, COUNT(DISTINCT a.id) c')->groupBy('consultant')->pluck('c', 'consultant')->all();
        $perConsultant = collect(array_keys($admByCons))->merge(array_keys($disByCons))->merge(array_keys($losByCons))
            ->merge(array_keys($consByCons))->merge(array_keys($signByCons))->merge(array_keys($readmitByCons))->unique()
            ->map(fn ($name) => [
                'name' => $name,
                'admissions' => (int) ($admByCons[$name] ?? 0),
                'discharges' => (int) ($disByCons[$name] ?? 0),
                'avgLos' => (float) ($losByCons[$name] ?? 0),
                'readmits' => (int) ($readmitByCons[$name] ?? 0),
                'consultations' => (int) ($consByCons[$name] ?? 0),
                'signoffs' => (int) ($signByCons[$name] ?? 0),
            ])->sortByDesc('admissions')->values();

        return ['kpiGrid' => $kpiGrid, 'series' => $series, 'perConsultant' => $perConsultant];
    }

    /**
     * Multi-sheet XLSX of the currently-filtered statistics (openspout v5 supports named sheets).
     * Reuses the openspout writer pattern from RegistryController and the EXACT query methods —
     * the KPI-grid sheet is the §3.8 "export == index" drift contract.
     */
    public function exportXlsx(StatisticsExportRequest $request): BinaryFileResponse
    {
        [, , $f, $t, $interval, $window] = $this->exportRange($request);
        $tables = $this->gatherExportTables($f, $t, $interval, $window);

        $tmp = tempnam(sys_get_temp_dir(), 'stat').'.xlsx';
        $writer = new XlsxWriter;
        $writer->openToFile($tmp);

        // Sheet 1 — KPI grid
        $writer->getCurrentSheet()->setName('KPI Grid');
        $writer->addRow(Row::fromValues(['Period', 'Adm', 'Disch', 'ICU adm', '→ICU', 'ICU deaths', 'Ward deaths', 'Readmits', 'Consults', 'Sign-offs', 'Avg LOS']));
        foreach ($tables['kpiGrid'] as $r) {
            $writer->addRow(Row::fromValues([$r['label'], $r['admissions'], $r['discharges'], $r['icu'], $r['transToIcu'],
                $r['icuDeaths'], $r['wardDeaths'], $r['readmits'], $r['consultations'], $r['signoffs'], $r['avgLos']]));
        }

        // Sheet 2 — per consultant
        $writer->addNewSheetAndMakeItCurrent()->setName('Per Consultant');
        $writer->addRow(Row::fromValues(['Consultant', 'Admissions', 'Discharges', 'Avg LOS', 'Readmits', 'Consultations', 'Sign-offs']));
        foreach ($tables['perConsultant'] as $r) {
            $writer->addRow(Row::fromValues([$r['name'], $r['admissions'], $r['discharges'], $r['avgLos'], $r['readmits'], $r['consultations'], $r['signoffs']]));
        }

        // Sheet 3 — interval series
        $writer->addNewSheetAndMakeItCurrent()->setName('Interval Series');
        $writer->addRow(Row::fromValues(['Period', 'Admissions', 'Discharges', 'Deaths', 'Consultations', 'Sign-offs']));
        foreach ($tables['series'] as $r) {
            $writer->addRow(Row::fromValues([$r['label'], $r['admissions'], $r['discharges'], $r['deaths'], $r['consultations'], $r['signoffs']]));
        }

        $writer->close();

        return response()->download($tmp, "dmc-statistics-{$f}-{$t}.xlsx")->deleteFileAfterSend();
    }

    /** Printable A4-landscape PDF of the currently-filtered statistics (KPI cards + grid + per-consultant). */
    public function exportPdf(StatisticsExportRequest $request): SymfonyResponse
    {
        [, , $f, $t, $interval, $window] = $this->exportRange($request);
        $tables = $this->gatherExportTables($f, $t, $interval, $window);

        // headline KPI tiles — same reconciled formulas as index()'s $kpis block
        $kpis = [
            'admissions' => (int) DB::table('admissions')->whereBetween('admit_date', [$f, $t])->whereRaw($this->nonIcu)->whereNull('deleted_at')->count(),
            'discharges' => (int) DB::table('admissions')->whereBetween('discharge_date', [$f, $t])->whereRaw($this->nonIcu)->whereNull('deleted_at')->count(),
            'deaths' => (int) DB::table('admissions')->where('outcome', 'Dead')->whereBetween('discharge_date', [$f, $t])->whereNull('deleted_at')->count(),
            'icuAdmissions' => (int) DB::table('admissions')->whereBetween('admit_date', [$f, $t])->where('current_location', 'ICU')->whereNull('deleted_at')->count(),
            'consultations' => (int) DB::table('consultations')->whereBetween('consultation_date', [$f, $t])->whereNull('deleted_at')->count(),
            'signoffs' => (int) DB::table('consultations')->whereBetween('signoff_date', [$f, $t])->whereNull('deleted_at')->count(),
            'avgLos' => round((float) (DB::table('admissions')->whereBetween('discharge_date', [$f, $t])->whereNotNull('admit_date')
                ->whereRaw($this->nonIcu)->whereNull('deleted_at')->whereRaw('DATEDIFF(discharge_date, admit_date) >= 0')
                ->avg(DB::raw('DATEDIFF(discharge_date, admit_date)')) ?? 0), 1),
            'readmissions' => (int) DB::table('admissions as a')->join('admissions as prev', $this->readmissionJoin($window))
                ->whereBetween('a.admit_date', [$f, $t])->whereNull('a.deleted_at')->whereNull('prev.deleted_at')->distinct()->count('a.id'),
        ];
        $kpis['mortalityRate'] = $kpis['discharges'] > 0 ? round($kpis['deaths'] / $kpis['discharges'] * 100, 1) : 0.0;

        $pdf = Pdf::loadView('reports.statistics-pdf', [
            'from' => $f, 'to' => $t, 'interval' => $interval, 'readmitWindow' => $window,
            'kpis' => $kpis, 'kpiGrid' => $tables['kpiGrid'], 'perConsultant' => $tables['perConsultant'],
            'generatedAt' => now()->format('D, d M Y · H:i'),
        ])->setPaper('a4', 'landscape');

        return $pdf->download("dmc-statistics-{$f}-{$t}.pdf");
    }
}
