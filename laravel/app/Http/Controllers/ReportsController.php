<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Printable A4 annual activity report. Sargable grouped SQL over the indexed date columns; the Vue
 * page is styled for A4 print (the user prints / saves-as-PDF from the browser, matching legacy parity).
 */
class ReportsController extends Controller
{
    private string $nonIcu = "(current_location <> 'ICU' OR current_location IS NULL)";

    public function index(Request $request): Response
    {
        $year = (int) ($request->query('year') ?: Carbon::today()->year);
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
            ->groupByRaw('COALESCE(u.full_name, u.name)')->orderByDesc('c')->limit(15)->get();

        $availableYears = DB::table('admissions')->selectRaw('DISTINCT YEAR(admit_date) y')
            ->whereNotNull('admit_date')->orderByDesc('y')->pluck('y')->filter()->values();

        return Inertia::render('Reports/Index', [
            'year' => $year,
            'availableYears' => $availableYears,
            'months' => $months,
            'totals' => $totals,
            'avgLos' => round($avgLos, 1),
            'topDx' => $topDx,
            'perConsultant' => $perConsultant,
            'generatedAt' => now()->format('D, d M Y · H:i'),
        ]);
    }

    private function byMonth(string $col, string $from, string $to, string $extra): array
    {
        return DB::table('admissions')->whereBetween($col, [$from, $to])->whereRaw($extra)
            ->selectRaw("DATE_FORMAT($col, '%Y-%m') m, COUNT(*) c")->groupBy('m')->pluck('c', 'm')->all();
    }
}
