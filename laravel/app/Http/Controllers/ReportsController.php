<?php

namespace App\Http\Controllers;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Annual activity report. The Vue page is print-styled; a true server-side PDF (dompdf) is also
 * available via /reports/pdf. Sargable grouped SQL over the indexed date columns.
 */
class ReportsController extends Controller
{
    private string $nonIcu = "(current_location <> 'ICU' OR current_location IS NULL)";

    public function index(Request $request): Response
    {
        $year = (int) ($request->query('year') ?: Carbon::today()->year);

        return Inertia::render('Reports/Index', [
            ...$this->gather($year),
            'availableYears' => DB::table('admissions')->selectRaw('DISTINCT YEAR(admit_date) y')
                ->whereNotNull('admit_date')->orderByDesc('y')->pluck('y')->filter()->values(),
        ]);
    }

    public function pdf(Request $request): SymfonyResponse
    {
        $year = (int) ($request->query('year') ?: Carbon::today()->year);
        $pdf = Pdf::loadView('reports.annual-pdf', $this->gather($year))->setPaper('a4', 'portrait');

        return $pdf->download("dmc-annual-report-{$year}.pdf");
    }

    /** Shared data set for both the screen page and the PDF. */
    private function gather(int $year): array
    {
        $start = "{$year}-01-01";
        $end = "{$year}-12-31";

        $admByMonth = $this->byMonth('admit_date', $start, $end, $this->nonIcu);
        $disByMonth = $this->byMonth('discharge_date', $start, $end, $this->nonIcu);
        $deathByMonth = $this->byMonth('discharge_date', $start, $end, "outcome = 'Dead'");
        $icuByMonth = $this->byMonth('admit_date', $start, $end, "current_location = 'ICU'");

        $months = [];
        for ($m = 1; $m <= 12; $m++) {
            $key = sprintf('%04d-%02d', $year, $m);
            $months[] = [
                'label' => Carbon::createFromDate($year, $m, 1)->format('F'),
                'admissions' => (int) ($admByMonth[$key] ?? 0),
                'discharges' => (int) ($disByMonth[$key] ?? 0),
                'icu' => (int) ($icuByMonth[$key] ?? 0),
                'deaths' => (int) ($deathByMonth[$key] ?? 0),
            ];
        }

        $totals = [
            'admissions' => array_sum(array_column($months, 'admissions')),
            'discharges' => array_sum(array_column($months, 'discharges')),
            'icu' => array_sum(array_column($months, 'icu')),
            'deaths' => array_sum(array_column($months, 'deaths')),
        ];
        $totals['mortalityRate'] = $totals['discharges'] > 0 ? round($totals['deaths'] / $totals['discharges'] * 100, 1) : 0;

        $avgLos = (float) DB::table('admissions')->whereBetween('discharge_date', [$start, $end])
            ->whereNotNull('admit_date')->whereRaw($this->nonIcu)->whereRaw('DATEDIFF(discharge_date, admit_date) >= 0')
            ->avg(DB::raw('DATEDIFF(discharge_date, admit_date)'));

        $topDx = DB::table('admission_diagnoses as ad')->join('admissions as a', 'a.id', '=', 'ad.admission_id')
            ->leftJoin('icd10 as i', 'i.code', '=', 'ad.icd10_code')
            ->whereBetween('a.admit_date', [$start, $end])
            ->selectRaw('ad.icd10_code code, MAX(i.name) name, COUNT(*) c')
            ->groupBy('ad.icd10_code')->orderByDesc('c')->limit(10)->get()
            ->map(fn ($r) => ['code' => $r->code, 'name' => $r->name ?: $r->code, 'count' => (int) $r->c]);

        $perConsultant = DB::table('admissions as a')->join('users as u', 'u.id', '=', 'a.consultant_id')
            ->whereBetween('a.admit_date', [$start, $end])
            ->selectRaw('COALESCE(u.full_name, u.name) name, COUNT(*) c')
            ->groupByRaw('COALESCE(u.full_name, u.name)')->orderByDesc('c')->limit(15)->get()
            ->map(fn ($r) => ['name' => $r->name, 'count' => (int) $r->c]);

        // discharge destinations for the year
        $destinations = DB::table('admissions')->whereBetween('discharge_date', [$start, $end])
            ->selectRaw("COALESCE(NULLIF(TRIM(discharge_to), ''), 'Unspecified') dest, COUNT(*) c")
            ->groupBy('dest')->orderByDesc('c')->limit(12)->get()
            ->map(fn ($r) => ['dest' => $r->dest, 'count' => (int) $r->c]);

        // average ward LOS per consultant (discharges in year)
        $perConsultantLos = DB::table('admissions as a')->join('users as u', 'u.id', '=', 'a.consultant_id')
            ->whereBetween('a.discharge_date', [$start, $end])->whereNotNull('a.admit_date')
            ->whereRaw($this->nonIcu)->whereRaw('DATEDIFF(a.discharge_date, a.admit_date) >= 0')
            ->selectRaw('COALESCE(u.full_name, u.name) name, ROUND(AVG(DATEDIFF(a.discharge_date, a.admit_date)), 1) los, COUNT(*) n')
            ->groupByRaw('COALESCE(u.full_name, u.name)')->orderByDesc('n')->limit(15)->get()
            ->map(fn ($r) => ['name' => $r->name, 'los' => (float) $r->los, 'n' => (int) $r->n]);

        $icuLos = (float) DB::table('admissions')->whereBetween('discharge_date', [$start, $end])
            ->whereNotNull('admit_date')->where('current_location', 'ICU')->whereRaw('DATEDIFF(discharge_date, admit_date) >= 0')
            ->avg(DB::raw('DATEDIFF(discharge_date, admit_date)'));

        return [
            'year' => $year,
            'months' => $months,
            'totals' => $totals,
            'avgLos' => round($avgLos, 1),
            'icuLos' => round($icuLos, 1),
            'topDx' => $topDx,
            'perConsultant' => $perConsultant,
            'destinations' => $destinations,
            'perConsultantLos' => $perConsultantLos,
            'generatedAt' => now()->format('D, d M Y · H:i'),
        ];
    }

    /** Per-day breakdown for one month (screen + PDF). */
    public function monthly(Request $request): Response
    {
        $year = (int) ($request->query('year') ?: Carbon::today()->year);
        $month = max(1, min(12, (int) ($request->query('month') ?: Carbon::today()->month)));

        return Inertia::render('Reports/Monthly', [
            ...$this->gatherMonth($year, $month),
            'availableYears' => DB::table('admissions')->selectRaw('DISTINCT YEAR(admit_date) y')
                ->whereNotNull('admit_date')->orderByDesc('y')->pluck('y')->filter()->values(),
        ]);
    }

    public function monthlyPdf(Request $request): SymfonyResponse
    {
        $year = (int) ($request->query('year') ?: Carbon::today()->year);
        $month = max(1, min(12, (int) ($request->query('month') ?: Carbon::today()->month)));
        $pdf = Pdf::loadView('reports.monthly-pdf', $this->gatherMonth($year, $month))->setPaper('a4', 'portrait');

        return $pdf->download("dmc-monthly-report-{$year}-" . sprintf('%02d', $month) . '.pdf');
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

    private function byDay(string $col, string $start, string $end, string $where): array
    {
        return DB::table('admissions')->whereBetween($col, [$start, $end])->whereRaw($where)
            ->selectRaw("DATE($col) k, COUNT(*) c")->groupBy('k')->pluck('c', 'k')->toArray();
    }

    private function byMonth(string $col, string $from, string $to, string $extra): array
    {
        return DB::table('admissions')->whereBetween($col, [$from, $to])->whereRaw($extra)
            ->selectRaw("DATE_FORMAT($col, '%Y-%m') m, COUNT(*) c")->groupBy('m')->pluck('c', 'm')->all();
    }
}
