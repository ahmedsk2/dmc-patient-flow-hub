<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\MetricQueries;
use App\Http\Requests\ConsultantScorecardRequest;
use App\Http\Requests\GovernanceReportRequest;
use App\Http\Requests\ReportYearRequest;
use App\Jobs\GenerateMonthlyPdf;
use App\Models\Admission;
use App\Models\Setting;
use App\Models\User;
use App\Support\Audit;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Annual + monthly activity reports. The Vue pages are print-styled; the true server-side PDFs
 * (dompdf, A4 LANDSCAPE) rebuild the legacy statistics/a4.php and a4-monthly.php booklets —
 * cover page, counter cards, monthly/daily overview charts, LOS charts, destination doughnut and
 * per-consultant LOS, all as inline SVG (App\Support\ReportSvg).
 *
 * Metric port notes (legacy a4.php is the spec):
 *  - all families use GROUPED sargable SQL over the indexed date columns — never per-month/per-day
 *    query loops (the legacy renovated file already proved the grouped versions byte-identical);
 *  - weekend discharge = non-ICU discharge on a FRIDAY/SATURDAY (DAYOFWEEK 6/7) — decision D4;
 *  - "Total Patients" / "Long Stay" cards are CENSUS-based (the exact a4.php month-presence and
 *    spans-a-month-or->30-days formulas) — decision D5. The pre-existing reconciled headline
 *    formulas (months[].admissions/discharges/icu/deaths/lsp, totals.*) are unchanged;
 *  - 72-hr readmissions reuse the ONE shared predicate (Admission::readmissionJoin + settings window).
 */
class ReportsController extends Controller
{
    use MetricQueries;

    private string $nonIcu = Admission::NON_ICU_SQL;

    public function index(Request $request): Response
    {
        // §3.7: the SCREEN is lenient on year (any sane integer) and silently CLAMPS to a year with
        // data — a future/empty year shows real numbers, not an all-zeros booklet (a redirect loop
        // would be worse UX). The PDF endpoint (pdf()) is strict via ReportYearRequest instead.
        $request->validate(['year' => ['nullable', 'integer', 'min:1900', 'max:9999']]);
        $year = (int) ($request->query('year') ?: Carbon::today()->year);

        $available = DB::table('admissions')->selectRaw('DISTINCT YEAR(admit_date) y')
            ->whereNotNull('admit_date')->whereNull('deleted_at')->orderByDesc('y')->pluck('y')->filter()->values();
        if ($available->isNotEmpty() && ! $available->contains($year)) {
            $year = (int) $available->first();
        }

        return Inertia::render('Reports/Index', [
            ...$this->gather($year),
            'availableYears' => $available,
        ]);
    }

    /**
     * §3.6 stopgap: bound a synchronous booklet render so a slow report cannot hold a web worker open
     * indefinitely.
     *
     * Deliberately a NO-OP on the CLI. PHP's CLI SAPI defaults max_execution_time to 0 (unlimited), and
     * the limit is per-PROCESS, not per-call — so setting it here caps everything that runs afterwards in
     * the same process. In PHPUnit that means one Reports test silently puts the whole remaining suite on
     * a 120-second budget and the run dies mid-way with "Premature end of PHP process"; in a queue worker
     * it would cap a legitimately long render (GenerateMonthlyPdf).
     */
    private function boundSyncRender(): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }
        @ini_set('max_execution_time', '120');
    }

    public function pdf(ReportYearRequest $request): SymfonyResponse
    {
        $this->boundSyncRender();
        $year = (int) ($request->validated('year') ?: Carbon::today()->year);
        $pdf = Pdf::loadView('reports.annual-pdf', $this->gather($year))->setPaper('a4', 'landscape');

        // prod-ready G1: break-glass row for the annual booklet export — aggregate figures only
        Audit::log('report.pdf.annual', 'report', null, ['year' => $year]);

        // classification: aggregate-only export (DATA-CLASSIFICATION.md §4/§6) — CONFIDENTIAL- filename prefix
        return $pdf->download("CONFIDENTIAL-dmc-annual-report-{$year}.pdf");
    }

    /**
     * Phase 3 — §3.1: per-consultant scorecard PDF (2-page A4 landscape) over a date range.
     * Reuses StatisticsController::physician (the SAME per-consultant figures the Statistics
     * drill-down shows) plus a unit-wide median ward LOS for the "vs unit" comparison. The
     * monthly trend is the physician's already month-bucketed activity series.
     */
    public function consultantPdf(ConsultantScorecardRequest $request, User $user, StatisticsController $stats): SymfonyResponse
    {
        $this->boundSyncRender();
        $data = $request->validated();
        $to = isset($data['to']) ? Carbon::parse($data['to']) : Carbon::today();
        $from = isset($data['from']) ? Carbon::parse($data['from']) : $to->copy()->startOfYear();
        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }
        $f = $from->toDateString();
        $t = $to->toDateString();

        $window = max(0, (int) (Setting::current()->readmission_window_days ?? 3));
        // hard-code MONTH interval for the PDF (a day-interval trend over a long range is unreadable on A4)
        $buckets = $this->buckets($from, $to, 'month');
        $physician = $stats->physician($user->id, $f, $t, $window, 'month', $buckets);

        // unit-wide avg ward LOS over the same range — the "vs unit median" comparison figure
        $unitMedianLos = round((float) (DB::table('admissions')->whereBetween('discharge_date', [$f, $t])
            ->whereNotNull('admit_date')->whereRaw($this->nonIcu)->whereNull('deleted_at')->whereRaw('DATEDIFF(discharge_date, admit_date) >= 0')
            ->avg(DB::raw('DATEDIFF(discharge_date, admit_date)')) ?? 0), 1);

        // weekend-discharge rate for this consultant (Fri/Sat non-ICU discharges — decision D4)
        $consDischarges = (int) DB::table('admissions')->where('consultant_id', $user->id)
            ->whereBetween('discharge_date', [$f, $t])->whereRaw($this->nonIcu)->whereNull('deleted_at')->count();
        $consWeekend = (int) DB::table('admissions')->where('consultant_id', $user->id)
            ->whereBetween('discharge_date', [$f, $t])->whereRaw($this->nonIcu)->whereNull('deleted_at')
            ->whereRaw('DAYOFWEEK(discharge_date) IN (6,7)')->count();
        $weekendPct = $consDischarges > 0 ? round($consWeekend / $consDischarges * 100, 1) : 0.0;

        $pdf = Pdf::loadView('reports.consultant-pdf', [
            'physician' => $physician,
            'monthlyTrend' => $physician['series'],   // alias for blade clarity
            'unitMedianLos' => $unitMedianLos,
            'weekend' => $consWeekend,
            'weekendPct' => $weekendPct,
            'readmitWindow' => $window,
            'from' => $f, 'to' => $t,
            'generatedAt' => now()->format('D, d M Y · H:i'),
        ])->setPaper('a4', 'landscape');

        // prod-ready G1: break-glass row for the scorecard export — consultant id only, no patient data
        Audit::log('report.pdf.consultant', 'report', null, ['consultant_id' => $user->id, 'from' => $f, 'to' => $t]);

        // classification: aggregate-only export (DATA-CLASSIFICATION.md §4/§6) — CONFIDENTIAL- filename prefix
        return $pdf->download("CONFIDENTIAL-scorecard-{$user->id}-{$f}-{$t}.pdf");
    }

    /** Phase 3 — §3.2: the Governance / M&M pack form (month/quarter picker + download). */
    public function governance(Request $request): Response
    {
        return Inertia::render('Reports/Governance', [
            'availableYears' => DB::table('admissions')->selectRaw('DISTINCT YEAR(admit_date) y')
                ->whereNotNull('admit_date')->whereNull('deleted_at')->orderByDesc('y')->pluck('y')->filter()->values(),
        ]);
    }

    /**
     * Phase 3 — §3.2: de-identified Morbidity & Mortality pack for a chosen month/quarter —
     * headline safety KPIs + trend bars + line lists of every death and every readmission in the
     * period. Reuses the SAME mortality/readmission/long-stay/weekend queries as the booklet and
     * Admission::readmissionJoin / REAL_DISCHARGE_TYPES. MRN is included (clinical identifier, same
     * de-identification policy as the registry exports); patient NAME is never included.
     */
    public function governancePdf(GovernanceReportRequest $request): SymfonyResponse
    {
        $this->boundSyncRender();
        $data = $request->validated();
        if ($data['period_type'] === 'quarter') {
            $startMonth = ($data['quarter'] - 1) * 3 + 1;
            $f = Carbon::createFromDate($data['year'], $startMonth, 1)->startOfMonth()->toDateString();
            $t = Carbon::createFromDate($data['year'], $startMonth, 1)->addMonths(2)->endOfMonth()->toDateString();
            $title = 'Q'.$data['quarter'].' '.$data['year'];
        } else {
            $f = Carbon::createFromDate($data['year'], $data['month'], 1)->startOfMonth()->toDateString();
            $t = Carbon::createFromDate($data['year'], $data['month'], 1)->endOfMonth()->toDateString();
            $title = Carbon::createFromDate($data['year'], $data['month'], 1)->format('F Y');
        }
        $window = max(0, (int) (Setting::current()->readmission_window_days ?? 3));

        // ---- headline safety KPIs (same formulas as gather()) ----
        $deaths = (int) DB::table('admissions')->where('outcome', 'Dead')->whereBetween('discharge_date', [$f, $t])->whereNull('deleted_at')->count();
        $discharges = (int) DB::table('admissions')->whereBetween('discharge_date', [$f, $t])->whereRaw($this->nonIcu)->whereNull('deleted_at')->count();
        $mortalityRate = $discharges > 0 ? round($deaths / $discharges * 100, 1) : 0.0;
        $readmissions = (int) DB::table('admissions as a')->join('admissions as prev', Admission::readmissionJoin($window))
            ->whereBetween('a.admit_date', [$f, $t])->whereNull('a.deleted_at')->whereNull('prev.deleted_at')->distinct()->count('a.id');
        $longLos = (int) (Setting::current()->long_los ?? 11);
        $longStay = (int) DB::table('admissions')->whereBetween('discharge_date', [$f, $t])->whereRaw($this->nonIcu)->whereNull('deleted_at')
            ->whereNotNull('admit_date')->whereRaw("DATEDIFF(discharge_date, admit_date) > {$longLos}")->count();
        $longStayPct = $discharges > 0 ? round($longStay / $discharges * 100, 1) : 0.0;
        $weekend = (int) DB::table('admissions')->whereBetween('discharge_date', [$f, $t])->whereRaw($this->nonIcu)->whereNull('deleted_at')
            ->whereRaw('DAYOFWEEK(discharge_date) IN (6,7)')->count();
        $weekendPct = $discharges > 0 ? round($weekend / $discharges * 100, 1) : 0.0;

        // ---- trend bars: 3 monthly buckets spanning the period (the byMonth families) ----
        $trendStart = Carbon::parse($f)->startOfMonth();
        $trendEnd = Carbon::parse($t)->endOfMonth();
        $admByMonth = $this->byMonth('admit_date', $trendStart->toDateString(), $trendEnd->toDateString(), $this->nonIcu);
        $disByMonth = $this->byMonth('discharge_date', $trendStart->toDateString(), $trendEnd->toDateString(), $this->nonIcu);
        $deathByMonth = $this->byMonth('discharge_date', $trendStart->toDateString(), $trendEnd->toDateString(), "outcome = 'Dead'");
        $readmitByMonth = $this->readmitsByMonth($trendStart->toDateString(), $trendEnd->toDateString(), $window);
        $trend = [];
        for ($c = $trendStart->copy(); $c->lte($trendEnd); $c->addMonth()) {
            $k = $c->format('Y-m');
            $trend[] = [
                'label' => $c->format('M'),
                'admissions' => (int) ($admByMonth[$k] ?? 0),
                'discharges' => (int) ($disByMonth[$k] ?? 0),
                'deaths' => (int) ($deathByMonth[$k] ?? 0),
                'readmits' => (int) ($readmitByMonth[$k] ?? 0),
            ];
        }

        // ---- death line list (DE-IDENTIFIED: MRN, no name) ----
        $deathList = DB::table('admissions as a')
            ->join('patients as p', 'p.id', '=', 'a.patient_id')
            ->leftJoin('users as u', 'u.id', '=', 'a.consultant_id')
            ->whereBetween('a.discharge_date', [$f, $t])->where('a.outcome', 'Dead')->whereNull('a.deleted_at')   // Phase 4 — Item 1
            ->select('a.id', 'p.mrn', 'p.age', DB::raw('DATEDIFF(a.discharge_date, a.admit_date) los'),
                'a.current_location', DB::raw('COALESCE(u.full_name, u.name) consultant'))
            ->orderBy('a.discharge_date')->get();
        // primary diagnosis name per death (small set — one whereIn load)
        $deathIds = $deathList->pluck('id');
        $primaryDx = $this->primaryDiagnoses($deathIds);
        $deathRows = $deathList->map(fn ($r) => [
            'mrn' => $r->mrn, 'age' => $r->age, 'los' => $r->los, 'location' => $r->current_location,
            'dx' => $primaryDx[$r->id] ?? '—', 'consultant' => $r->consultant ?: '—',
        ]);

        // ---- readmission line list (DE-IDENTIFIED, reuses readmissionJoin) ----
        $readmitRows = DB::table('admissions as a')
            ->join('admissions as prev', Admission::readmissionJoin($window))
            ->join('patients as p', 'p.id', '=', 'a.patient_id')
            ->leftJoin('users as u', 'u.id', '=', 'a.consultant_id')
            ->whereBetween('a.admit_date', [$f, $t])->whereNull('a.deleted_at')->whereNull('prev.deleted_at')->distinct()   // Phase 4 — Item 1
            ->select('p.mrn', 'p.age', 'a.admit_date', DB::raw('DATEDIFF(a.admit_date, prev.discharge_date) gap_days'),
                DB::raw('COALESCE(u.full_name, u.name) consultant'))
            ->orderBy('a.admit_date')->get()
            ->map(fn ($r) => ['mrn' => $r->mrn, 'age' => $r->age,
                'admit_date' => $r->admit_date, 'gap_days' => $r->gap_days, 'consultant' => $r->consultant ?: '—']);

        $pdf = Pdf::loadView('reports.governance-pdf', [
            'title' => $title, 'from' => $f, 'to' => $t, 'readmitWindow' => $window,
            'kpis' => ['mortalityRate' => $mortalityRate, 'deaths' => $deaths, 'readmissions' => $readmissions,
                'longStay' => $longStay, 'longStayPct' => $longStayPct, 'weekend' => $weekend, 'weekendPct' => $weekendPct],
            'trend' => $trend, 'deaths' => $deathRows, 'readmits' => $readmitRows,
            'generatedAt' => now()->format('D, d M Y · H:i'),
        ])->setPaper('a4', 'landscape');

        // prod-ready G1: break-glass row for the governance pack — the pack itself carries MRNs, so
        // this is a PHI-read event; the audit DETAIL stays counts-only (period + line-list sizes),
        // never the MRNs/ages/consultants those line lists actually contain.
        Audit::log('report.pdf.governance', 'report', null, [
            'year' => $data['year'], 'period_type' => $data['period_type'],
            'month' => $data['month'] ?? null, 'quarter' => $data['quarter'] ?? null,
            'death_count' => $deathRows->count(), 'readmit_count' => $readmitRows->count(),
        ]);

        // classification: row-level patient data (MRN line lists) — SECRET- filename prefix
        return $pdf->download('SECRET-governance-'.$data['year'].'-'.($data['period_type'] === 'quarter' ? "Q{$data['quarter']}" : sprintf('%02d', $data['month'])).'.pdf');
    }

    /** Primary (seq-first) diagnosis name per admission id, for the governance death list. */
    private function primaryDiagnoses($admissionIds): array
    {
        if ($admissionIds->isEmpty()) {
            return [];
        }
        $rows = DB::table('admission_diagnoses as ad')->leftJoin('icd10 as i', 'i.code', '=', 'ad.icd10_code')
            ->whereIn('ad.admission_id', $admissionIds)
            ->orderBy('ad.admission_id')->orderBy('ad.seq')
            ->get(['ad.admission_id', 'ad.icd10_code', 'i.name']);
        $out = [];
        foreach ($rows as $r) {
            if (! isset($out[$r->admission_id])) {
                $out[$r->admission_id] = $r->name ?: $r->icd10_code;
            }
        }

        return $out;
    }

    /** Shared data set for both the screen page and the PDF. */
    private function gather(int $year): array
    {
        $start = "{$year}-01-01";
        $end = "{$year}-12-31";
        $today = Carbon::today()->toDateString();
        $capEnd = min($end, $today); // legacy caps "as of today" (lexical compare is safe for Y-m-d)

        $admByMonth = $this->byMonth('admit_date', $start, $end, $this->nonIcu);
        $disByMonth = $this->byMonth('discharge_date', $start, $end, $this->nonIcu);
        $deathByMonth = $this->byMonth('discharge_date', $start, $end, "outcome = 'Dead'");
        $icuByMonth = $this->byMonth('admit_date', $start, $end, "current_location = 'ICU'");

        // long-stay (threshold flavour) = ward discharge with LOS strictly greater than long_los
        $longLos = (int) (Setting::current()->long_los ?? 11);
        $longStayByMonth = $this->byMonth('discharge_date', $start, $end, $this->nonIcu." AND admit_date IS NOT NULL AND DATEDIFF(discharge_date, admit_date) > {$longLos}");

        // ---- legacy booklet families (grouped ports of statistics/a4.php) ----
        $consByMonth = $this->byMonth('consultation_date', $start, $end, '1=1', 'consultations');
        $sgnByMonth = $this->byMonth('signoff_date', $start, $end, '1=1', 'consultations');
        $icuTransByMonth = $this->byMonth('discharge_date', $start, $end, "discharge_to = 'Intensive Care (ICU)'");
        $weekendByMonth = $capEnd >= $start
            ? $this->byMonth('discharge_date', $start, $capEnd, $this->nonIcu.' AND DAYOFWEEK(discharge_date) IN (6,7)') // Fri/Sat (D4)
            : [];
        $readmitWindow = max(0, (int) (Setting::current()->readmission_window_days ?? 3));
        $readmitByMonth = $this->readmitsByMonth($start, $end, $readmitWindow);
        $clinLosByMonth = $this->losByMonth('medical_discharge_date', $this->nonIcu, $start, $end);
        $physLosByMonth = $this->losByMonth('discharge_date', $this->nonIcu, $start, $end);
        $icuLosByMonth = $this->losByMonth('discharge_date', "current_location = 'ICU'", $start, $end);
        [$censusByMonth, $censusLongByMonth, $bedDaysByMonth] = $this->censusFamilies($year);

        $months = [];
        $totalLongStay = 0;
        for ($m = 1; $m <= 12; $m++) {
            $key = sprintf('%04d-%02d', $year, $m);
            $dis = (int) ($disByMonth[$key] ?? 0);
            $long = (int) ($longStayByMonth[$key] ?? 0);
            $totalLongStay += $long;
            $wknd = (int) ($weekendByMonth[$key] ?? 0);
            $months[] = [
                'label' => Carbon::createFromDate($year, $m, 1)->format('F'),
                'admissions' => (int) ($admByMonth[$key] ?? 0),
                'discharges' => $dis,
                'icu' => (int) ($icuByMonth[$key] ?? 0),
                'deaths' => (int) ($deathByMonth[$key] ?? 0),
                'lsp' => $dis > 0 ? round($long / $dis * 100, 1) : 0.0,
                // legacy booklet additions (page 2-4 of the PDF)
                'consultations' => (int) ($consByMonth[$key] ?? 0),
                'signoffs' => (int) ($sgnByMonth[$key] ?? 0),
                'readmits' => (int) ($readmitByMonth[$key] ?? 0),
                'transToIcu' => (int) ($icuTransByMonth[$key] ?? 0),
                'weekend' => $wknd,
                'weekendPct' => $dis > 0 ? round($wknd / $dis * 100, 2) : 0.0,
                'bedDays' => (int) ($bedDaysByMonth[$key] ?? 0),
                'clinicalLos' => (float) ($clinLosByMonth[$key] ?? 0),
                'physicalLos' => (float) ($physLosByMonth[$key] ?? 0),
                'icuLos' => (float) ($icuLosByMonth[$key] ?? 0),
                'census' => (int) ($censusByMonth[$key] ?? 0),
                'longStayCensus' => (int) ($censusLongByMonth[$key] ?? 0),
            ];
        }

        $totals = [
            'admissions' => array_sum(array_column($months, 'admissions')),
            'discharges' => array_sum(array_column($months, 'discharges')),
            'icu' => array_sum(array_column($months, 'icu')),
            'deaths' => array_sum(array_column($months, 'deaths')),
        ];
        $totals['mortalityRate'] = $totals['discharges'] > 0 ? round($totals['deaths'] / $totals['discharges'] * 100, 1) : 0;
        $totals['lsp'] = $totals['discharges'] > 0 ? round($totalLongStay / $totals['discharges'] * 100, 1) : 0;

        // legacy page-2 counter cards: the exact a4.php card formulas (census sums — D5)
        $legacyTotalPatients = array_sum($censusByMonth);
        $legacyLongStay = array_sum($censusLongByMonth);
        $weekendTotal = array_sum(array_column($months, 'weekend'));
        $legacy = [
            'totalPatients' => $legacyTotalPatients,
            'weekend' => $weekendTotal,
            'weekendPct' => $totals['discharges'] > 0 ? round($weekendTotal / $totals['discharges'] * 100, 2) : 0.0,
            'longStay' => $legacyLongStay,
            'longStayPct' => $legacyTotalPatients > 0 ? round($legacyLongStay / $legacyTotalPatients * 100, 2) : 0.0,
            'readmissions' => array_sum(array_column($months, 'readmits')),
            'destinations' => $this->destinationBuckets($start, $end),
            'consultantLos' => $this->consultantLos($start, $end),
        ];

        $avgLos = (float) DB::table('admissions')->whereBetween('discharge_date', [$start, $end])
            ->whereNotNull('admit_date')->whereRaw($this->nonIcu)->whereNull('deleted_at')->whereRaw('DATEDIFF(discharge_date, admit_date) >= 0')
            ->avg(DB::raw('DATEDIFF(discharge_date, admit_date)'));

        $topDx = DB::table('admission_diagnoses as ad')->join('admissions as a', 'a.id', '=', 'ad.admission_id')
            ->leftJoin('icd10 as i', 'i.code', '=', 'ad.icd10_code')
            ->whereBetween('a.admit_date', [$start, $end])->whereNull('a.deleted_at')   // Phase 4 — Item 1
            ->selectRaw('ad.icd10_code code, MAX(i.name) name, COUNT(*) c')
            ->groupBy('ad.icd10_code')->orderByDesc('c')->limit(10)->get()
            ->map(fn ($r) => ['code' => $r->code, 'name' => $r->name ?: $r->code, 'count' => (int) $r->c]);

        $perConsultant = DB::table('admissions as a')->join('users as u', 'u.id', '=', 'a.consultant_id')
            ->whereBetween('a.admit_date', [$start, $end])->whereNull('a.deleted_at')   // Phase 4 — Item 1
            ->selectRaw('COALESCE(u.full_name, u.name) consultant, COUNT(*) c')   // non-colliding alias (cross-engine GROUP BY)
            ->groupBy('consultant')->orderByDesc('c')->limit(15)->get()
            ->map(fn ($r) => ['name' => $r->consultant, 'count' => (int) $r->c]);

        // discharge destinations for the year
        $destinations = DB::table('admissions')->whereBetween('discharge_date', [$start, $end])
            ->whereNull('deleted_at')   // Phase 4 — Item 1
            ->selectRaw("COALESCE(NULLIF(TRIM(discharge_to), ''), 'Unspecified') dest, COUNT(*) c")
            ->groupBy('dest')->orderByDesc('c')->limit(12)->get()
            ->map(fn ($r) => ['dest' => $r->dest, 'count' => (int) $r->c]);

        // average ward LOS per consultant (discharges in year)
        $perConsultantLos = DB::table('admissions as a')->join('users as u', 'u.id', '=', 'a.consultant_id')
            ->whereBetween('a.discharge_date', [$start, $end])->whereNotNull('a.admit_date')->whereNull('a.deleted_at')   // Phase 4 — Item 1
            ->whereRaw($this->nonIcu)->whereRaw('DATEDIFF(a.discharge_date, a.admit_date) >= 0')
            ->selectRaw('COALESCE(u.full_name, u.name) consultant, ROUND(AVG(DATEDIFF(a.discharge_date, a.admit_date)), 1) los, COUNT(*) n')
            ->groupBy('consultant')->orderByDesc('n')->limit(15)->get()
            ->map(fn ($r) => ['name' => $r->consultant, 'los' => (float) $r->los, 'n' => (int) $r->n]);

        $icuLos = (float) DB::table('admissions')->whereBetween('discharge_date', [$start, $end])
            ->whereNotNull('admit_date')->where('current_location', 'ICU')->whereNull('deleted_at')->whereRaw('DATEDIFF(discharge_date, admit_date) >= 0')
            ->avg(DB::raw('DATEDIFF(discharge_date, admit_date)'));

        return [
            'year' => $year,
            'months' => $months,
            'totals' => $totals,
            'legacy' => $legacy,
            'avgLos' => round($avgLos, 1),
            'icuLos' => round($icuLos, 1),
            'topDx' => $topDx,
            'perConsultant' => $perConsultant,
            'destinations' => $destinations,
            'perConsultantLos' => $perConsultantLos,
            'generatedAt' => now()->format('D, d M Y · H:i'),
        ];
    }

    /** Per-day breakdown for one month (screen page only — the PDF is the full-year booklet). */
    public function monthly(Request $request): Response
    {
        $year = (int) ($request->query('year') ?: Carbon::today()->year);
        $month = max(1, min(12, (int) ($request->query('month') ?: Carbon::today()->month)));

        return Inertia::render('Reports/Monthly', [
            ...$this->gatherMonth($year, $month),
            'availableYears' => DB::table('admissions')->selectRaw('DISTINCT YEAR(admit_date) y')
                ->whereNotNull('admit_date')->whereNull('deleted_at')->orderByDesc('y')->pluck('y')->filter()->values(),
        ]);
    }

    /**
     * Full-year monthly booklet (legacy statistics/a4-monthly.php): two landscape pages per elapsed
     * month. Synchronous by default (existing behaviour); ?async=1 dispatches the queued job
     * (§3.6) which stores the PDF and notifies the requester when ready.
     */
    public function monthlyPdf(Request $request): SymfonyResponse|RedirectResponse
    {
        $year = (int) ($request->query('year') ?: Carbon::today()->year);

        if ($request->boolean('async')) {
            GenerateMonthlyPdf::dispatch($year, (int) auth()->id());

            return back()->with('flash', ['type' => 'info',
                'message' => 'Your PDF is being generated — you will be notified when it is ready.']);
        }

        $this->boundSyncRender();
        $pdf = Pdf::loadView('reports.monthly-pdf', $this->gatherBooklet($year))->setPaper('a4', 'landscape');

        // prod-ready G1: break-glass row for the synchronous monthly-booklet export (aggregate
        // figures only). The ?async=1 path is a dispatch, not an export — it returns no file, so it
        // is not logged here; the eventual download is covered by downloadGenerated() below.
        Audit::log('report.pdf.monthly', 'report', null, ['year' => $year]);

        // classification: aggregate-only export (DATA-CLASSIFICATION.md §4/§6) — CONFIDENTIAL- filename prefix
        return $pdf->download("CONFIDENTIAL-dmc-monthly-report-{$year}.pdf");
    }

    /**
     * Phase 3 — §3.6: stream a queued-generated booklet PDF stored under storage/app/reports and
     * delete it after send. Admin-only (route group). The file lives on the private `local` disk —
     * no PHI is ever exposed through the public disk.
     */
    public function downloadGenerated(string $key): SymfonyResponse
    {
        $path = "reports/{$key}";
        abort_unless(Storage::disk('local')->exists($path), 404);
        $abs = Storage::disk('local')->path($path);

        // prod-ready G1: break-glass row for the queued-generated booklet download — the storage
        // key is a non-PHI filename (monthly-{year}-{userId}.pdf, see GenerateMonthlyPdf)
        Audit::log('report.pdf.download', 'report', null, ['key' => $key]);

        // classification: aggregate-only export (DATA-CLASSIFICATION.md §4/§6) — CONFIDENTIAL- filename prefix
        return response()->download($abs, 'CONFIDENTIAL-'.basename($path))->deleteFileAfterSend();
    }

    private function gatherMonth(int $year, int $month): array
    {
        $start = Carbon::createFromDate($year, $month, 1)->startOfDay();
        $end = (clone $start)->endOfMonth();
        $s = $start->toDateString();
        $e = $end->toDateString();

        $admByDay = $this->byDay('admit_date', $s, $e, $this->nonIcu);
        $disByDay = $this->byDay('discharge_date', $s, $e, $this->nonIcu);
        $icuByDay = $this->byDay('admit_date', $s, $e, "current_location = 'ICU'");
        $deathByDay = $this->byDay('discharge_date', $s, $e, "outcome = 'Dead'");

        $days = [];
        for ($d = 1; $d <= $end->day; $d++) {
            $key = sprintf('%04d-%02d-%02d', $year, $month, $d);
            $days[] = [
                'day' => $d,
                'weekday' => Carbon::createFromDate($year, $month, $d)->format('D'),
                'admissions' => (int) ($admByDay[$key] ?? 0),
                'discharges' => (int) ($disByDay[$key] ?? 0),
                'icu' => (int) ($icuByDay[$key] ?? 0),
                'deaths' => (int) ($deathByDay[$key] ?? 0),
            ];
        }

        return [
            'year' => $year,
            'month' => $month,
            'monthName' => $start->format('F'),
            'days' => $days,
            'totals' => [
                'admissions' => array_sum(array_column($days, 'admissions')),
                'discharges' => array_sum(array_column($days, 'discharges')),
                'icu' => array_sum(array_column($days, 'icu')),
                'deaths' => array_sum(array_column($days, 'deaths')),
            ],
            'generatedAt' => now()->format('D, d M Y · H:i'),
        ];
    }

    /**
     * Year booklet data: every ELAPSED month of $year (legacy skip rule: a month is included
     * once its first day is in the past). All series come from year-wide grouped queries that
     * are then split per month in PHP — no per-month/per-day query loops.
     */
    public function gatherBooklet(int $year): array
    {
        $start = "{$year}-01-01";
        $end = "{$year}-12-31";
        $today = Carbon::today();
        $capEnd = min($end, $today->toDateString());

        // per-day 4-series (daily overview chart), one grouped query each
        $admByDay = $this->byDay('admit_date', $start, $end, $this->nonIcu);
        $disByDay = $this->byDay('discharge_date', $start, $end, $this->nonIcu);
        $conByDay = $this->byDay('consultation_date', $start, $end, '1=1', 'consultations');
        $sgnByDay = $this->byDay('signoff_date', $start, $end, '1=1', 'consultations');

        // per-month families (same grouped ports as gather())
        $admByMonth = $this->byMonth('admit_date', $start, $end, $this->nonIcu);
        $disByMonth = $this->byMonth('discharge_date', $start, $end, $this->nonIcu);
        $deathByMonth = $this->byMonth('discharge_date', $start, $end, "outcome = 'Dead'");
        $weekendByMonth = $capEnd >= $start
            ? $this->byMonth('discharge_date', $start, $capEnd, $this->nonIcu.' AND DAYOFWEEK(discharge_date) IN (6,7)') // Fri/Sat (D4)
            : [];
        $readmitWindow = max(0, (int) (Setting::current()->readmission_window_days ?? 3));
        $readmitByMonth = $this->readmitsByMonth($start, $end, $readmitWindow);
        $clinLosByMonth = $this->losByMonth('medical_discharge_date', $this->nonIcu, $start, $end);
        $physLosByMonth = $this->losByMonth('discharge_date', $this->nonIcu, $start, $end);
        $icuLosByMonth = $this->losByMonth('discharge_date', "current_location = 'ICU'", $start, $end);

        // census + long-stay cards (J1-14): the a4-monthly "Total Patients" / "Long Stay" counters
        // reuse the exact a4.php census month-presence formulas (censusFamilies, shared with the
        // annual booklet); incomplete months render "Pending" like legacy (null below)
        [$censusByMonth, $censusLongByMonth] = $this->censusFamilies($year);

        // destination buckets per month — one grouped query, bucketed in PHP
        $destByMonth = [];
        DB::table('admissions')->whereBetween('discharge_date', [$start, $end])->whereRaw($this->nonIcu)
            ->whereNull('deleted_at')   // Phase 4 — Item 1
            ->selectRaw("DATE_FORMAT(discharge_date, '%Y-%m') mk, COALESCE(discharge_to, '') dst, COUNT(*) c")
            ->groupBy('mk', 'dst')->get()
            ->each(function ($r) use (&$destByMonth) {
                $destByMonth[$r->mk][$r->dst] = (int) $r->c;
            });

        // per-(month, consultant) physical LOS — one grouped query; zeros for every active consultant
        $consultants = User::where('role', User::ROLE_CONSULTANT)->where('active', 1)
            ->orderBy('full_name')->get(['id', 'full_name', 'name']);
        $cLosByMonth = [];
        DB::table('admissions')->whereBetween('discharge_date', [$start, $end])->whereRaw($this->nonIcu)
            ->whereNotNull('admit_date')->whereNotNull('consultant_id')->whereNull('deleted_at')   // Phase 4 — Item 1
            ->whereRaw('DATEDIFF(discharge_date, admit_date) >= 0')
            ->selectRaw("DATE_FORMAT(discharge_date, '%Y-%m') mk, consultant_id cid, ROUND(AVG(DATEDIFF(discharge_date, admit_date)), 1) v")
            ->groupBy('mk', 'cid')->get()
            ->each(function ($r) use (&$cLosByMonth) {
                $cLosByMonth[$r->mk][(int) $r->cid] = (float) $r->v;
            });

        $months = [];
        for ($m = 1; $m <= 12; $m++) {
            $first = Carbon::createFromDate($year, $m, 1)->startOfDay();
            if ($first->gte($today)) {
                continue;
            } // legacy rule: skip months not yet started
            $key = sprintf('%04d-%02d', $year, $m);

            $days = ['labels' => [], 'admissions' => [], 'discharges' => [], 'consultations' => [], 'signoffs' => []];
            for ($d = 1; $d <= $first->daysInMonth; $d++) {
                $dk = sprintf('%04d-%02d-%02d', $year, $m, $d);
                $days['labels'][] = (string) $d;
                $days['admissions'][] = (int) ($admByDay[$dk] ?? 0);
                $days['discharges'][] = (int) ($disByDay[$dk] ?? 0);
                $days['consultations'][] = (int) ($conByDay[$dk] ?? 0);
                $days['signoffs'][] = (int) ($sgnByDay[$dk] ?? 0);
            }

            $dis = (int) ($disByMonth[$key] ?? 0);
            $wknd = (int) ($weekendByMonth[$key] ?? 0);
            // census cards are only meaningful once the month has fully elapsed (legacy "Pending")
            $monthDone = $first->copy()->endOfMonth()->toDateString() <= $today->toDateString();
            $census = (int) ($censusByMonth[$key] ?? 0);
            $longStay = (int) ($censusLongByMonth[$key] ?? 0);
            $months[] = [
                'm' => $m,
                'name' => $first->format('F'),
                'days' => $days,
                'cards' => [
                    'admissions' => (int) ($admByMonth[$key] ?? 0),
                    'discharges' => $dis,
                    'deaths' => (int) ($deathByMonth[$key] ?? 0),
                    'weekend' => $wknd,
                    'weekendPct' => $dis > 0 ? round($wknd / $dis * 100, 2) : 0.0,
                    'readmits' => (int) ($readmitByMonth[$key] ?? 0),
                    // null => the blade renders "Pending" (incomplete month, legacy a4-monthly)
                    'census' => $monthDone ? $census : null,
                    'longStay' => $monthDone ? $longStay : null,
                    'longStayPct' => ($monthDone && $census > 0) ? round($longStay / $census * 100, 2) : 0.0,
                ],
                'los' => [
                    'clinical' => (float) ($clinLosByMonth[$key] ?? 0),
                    'physical' => (float) ($physLosByMonth[$key] ?? 0),
                    'icu' => (float) ($icuLosByMonth[$key] ?? 0),
                ],
                'destinations' => Admission::bucketizeDestinations($destByMonth[$key] ?? []),
                'consultantLos' => $consultants
                    ->map(fn ($u) => ['name' => $u->full_name ?: $u->name, 'value' => (float) ($cLosByMonth[$key][$u->id] ?? 0)])
                    ->values()->all(),
            ];
        }

        return [
            'year' => $year,
            'months' => $months,
            'generatedAt' => now()->format('D, d M Y · H:i'),
        ];
    }

    /* ----------------------------- legacy metric helpers ----------------------------- */

    /** Readmissions per month — the shared window predicate, grouped over the whole range. */
    private function readmitsByMonth(string $start, string $end, int $window): array
    {
        return DB::table('admissions as a')->join('admissions as prev', Admission::readmissionJoin($window))
            ->whereBetween('a.admit_date', [$start, $end])
            ->whereNull('a.deleted_at')->whereNull('prev.deleted_at')   // Phase 4 — Item 1: both sides of the readmit join
            ->selectRaw("DATE_FORMAT(a.admit_date, '%Y-%m') mk, COUNT(DISTINCT a.id) c")
            ->groupBy('mk')->pluck('c', 'mk')->all();
    }

    /**
     * Average LOS per month of discharge (legacy buckets all three variants by MONTH(DISDATE)):
     * clinical passes medical_discharge_date as $endCol, physical/ICU pass discharge_date.
     */
    private function losByMonth(string $endCol, string $extra, string $from, string $to): array
    {
        return DB::table('admissions')->whereBetween('discharge_date', [$from, $to])
            ->whereRaw($extra)->whereNotNull('admit_date')->whereNotNull($endCol)
            ->whereNull('deleted_at')   // Phase 4 — Item 1
            ->whereRaw("DATEDIFF($endCol, admit_date) >= 0")
            ->selectRaw("DATE_FORMAT(discharge_date, '%Y-%m') mk, ROUND(AVG(DATEDIFF($endCol, admit_date)), 2) v")
            ->groupBy('mk')->pluck('v', 'mk')->all();
    }

    /**
     * The three census-based families of a4.php, from ONE fetch of the year's overlapping
     * episodes (non-ICU), evaluated per month in PHP with the EXACT legacy predicates:
     *  - census ("total number of patients at that month"): the 4-clause month-presence OR;
     *  - long stay: spans the whole month, or active and admitted >30 days before month end;
     *    both are 0 for months whose last day hasn't passed (legacy "Pending");
     *  - bed-days: per-episode overlap with [first, min(last, today)], same-day discharges excluded.
     */
    private function censusFamilies(int $year): array
    {
        $yStart = "{$year}-01-01";
        $yEnd = "{$year}-12-31";
        $today = Carbon::today()->toDateString();

        $rows = DB::table('admissions')->whereRaw($this->nonIcu)->whereNull('deleted_at')   // Phase 4 — Item 1
            ->where(function ($q) use ($yStart, $yEnd) {
                $q->where(fn ($w) => $w->where('admit_date', '<=', $yEnd)
                    ->where(fn ($x) => $x->whereNull('discharge_date')->orWhere('discharge_date', '>=', $yStart)))
                    ->orWhereBetween('admit_date', [$yStart, $yEnd])
                    ->orWhereBetween('discharge_date', [$yStart, $yEnd]);
            })
            ->get(['admit_date', 'discharge_date']);

        $census = $long = $beds = [];
        for ($m = 1; $m <= 12; $m++) {
            $first = sprintf('%04d-%02d-01', $year, $m);
            $last = date('Y-m-t', strtotime($first));
            $key = sprintf('%04d-%02d', $year, $m);
            $monthDone = $last <= $today;
            $cap = min($last, $today);
            $c = $l = $b = 0;

            foreach ($rows as $r) {
                $adm = $r->admit_date;
                $dis = $r->discharge_date;
                if ($monthDone) {
                    // census — legacy 4-clause OR (active-in-month / discharged-in / spans / admitted-in)
                    if (($dis === null && $adm !== null && $adm <= $last)
                        || ($dis !== null && $dis >= $first && $dis <= $last)
                        || ($dis !== null && $adm !== null && $adm < $first && $dis > $last)
                        || ($adm !== null && $adm >= $first && $adm <= $last)) {
                        $c++;
                    }
                    // long stay — spans the month, or active with >30 days before month end
                    if (($dis !== null && $adm !== null && $adm < $first && $dis >= $last)
                        || ($dis === null && $adm !== null && date('Y-m-d', strtotime($adm.' +30 days')) < $last)) {
                        $l++;
                    }
                }
                // bed-days — overlap with [first, cap]; same-day discharges excluded (NOT DISDATE <=> ADMDATE)
                if ($cap >= $first && $adm !== null && $adm !== $dis && $adm <= $cap && ($dis === null || $dis >= $first)) {
                    $b += max(0, $this->dayDiff(max($adm, $first), min($cap, $dis ?? $cap)) + 1);
                }
            }
            $census[$key] = $c;
            $long[$key] = $l;
            $beds[$key] = $b;
        }

        return [$census, $long, $beds];
    }

    /**
     * Legacy 7-bucket destination split over non-ICU closed episodes — bucketing itself lives
     * on Admission::bucketizeDestinations (shared with the Statistics donut).
     */
    private function destinationBuckets(string $from, string $to): array
    {
        $byDest = DB::table('admissions')->whereBetween('discharge_date', [$from, $to])->whereRaw($this->nonIcu)
            ->whereNull('deleted_at')   // Phase 4 — Item 1
            ->selectRaw("COALESCE(discharge_to, '') dst, COUNT(*) c")
            ->groupBy('dst')->pluck('c', 'dst')->all();

        return Admission::bucketizeDestinations($byDest);
    }

    /** Per-consultant physical LOS over a range — ALL active consultant-role users, zeros included. */
    private function consultantLos(string $from, string $to): array
    {
        $byId = DB::table('admissions')->whereBetween('discharge_date', [$from, $to])->whereRaw($this->nonIcu)
            ->whereNotNull('admit_date')->whereNotNull('consultant_id')->whereNull('deleted_at')   // Phase 4 — Item 1
            ->whereRaw('DATEDIFF(discharge_date, admit_date) >= 0')
            ->selectRaw('consultant_id cid, ROUND(AVG(DATEDIFF(discharge_date, admit_date)), 2) v')
            ->groupBy('cid')->pluck('v', 'cid')->all();

        return User::where('role', User::ROLE_CONSULTANT)->where('active', 1)->orderBy('full_name')
            ->get(['id', 'full_name', 'name'])
            ->map(fn ($u) => ['name' => $u->full_name ?: $u->name, 'value' => (float) ($byId[$u->id] ?? 0)])
            ->values()->all();
    }

    /** Whole-day difference between two Y-m-d strings (no-DST-safe via rounding). */
    private function dayDiff(string $from, string $to): int
    {
        return (int) round((strtotime($to) - strtotime($from)) / 86400);
    }

    private function byDay(string $col, string $start, string $end, string $where, string $table = 'admissions'): array
    {
        return DB::table($table)->whereBetween($col, [$start, $end])->whereRaw($where)
            ->whereNull('deleted_at')   // Phase 4 — Item 1: exclude soft-deleted (admissions + consultations)
            ->selectRaw("DATE($col) k, COUNT(*) c")->groupBy('k')->pluck('c', 'k')->toArray();
    }

    private function byMonth(string $col, string $from, string $to, string $extra, string $table = 'admissions'): array
    {
        return DB::table($table)->whereBetween($col, [$from, $to])->whereRaw($extra)
            ->whereNull('deleted_at')   // Phase 4 — Item 1: exclude soft-deleted (admissions + consultations)
            ->selectRaw("DATE_FORMAT($col, '%Y-%m') m, COUNT(*) c")->groupBy('m')->pluck('c', 'm')->all();
    }
}
