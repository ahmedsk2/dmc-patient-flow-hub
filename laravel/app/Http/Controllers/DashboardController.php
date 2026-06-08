<?php

namespace App\Http\Controllers;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Dashboard metrics for the re-platformed app.
 *
 * For Phase 1 this reads the ORIGINAL renovated database over the read-only `legacy` connection,
 * using the metric definitions validated in docs/METRICS.md (non-ICU = current_location != 'ICU'
 * OR NULL; consultations by consultation_date, sign-offs by signoff_date; 72h readmission; etc.).
 * When the clean schema + `legacy:import` land, this swaps to the new Eloquent models with no
 * change to the Vue layer (the JSON shape stays the same).
 */
class DashboardController extends Controller
{
    private function db()
    {
        return DB::connection('legacy');
    }

    private string $nonIcu = "(current_location <> 'ICU' OR current_location IS NULL)";

    public function index(): Response
    {
        $db = $this->db();
        $today = Carbon::today()->toDateString();
        $monthStart = Carbon::today()->startOfMonth()->toDateString();
        $yearStart = Carbon::today()->startOfYear()->toDateString();

        // --- settings (capacity) ---
        $settings = $db->table('settings')->where('id', 0)->first();
        $maxHospitalist = (int) ($settings->max_hospitalist ?? 30);

        // --- census ---
        $active = (int) $db->table('picupatients')->whereNull('DISDATE')->count();
        $activeIcu = (int) $db->table('picupatients')->whereNull('DISDATE')->where('current_location', 'ICU')->count();
        $activeWard = $active - $activeIcu;

        $admissionsToday = (int) $db->table('picupatients')->whereDate('ADMDATE', $today)->whereRaw($this->nonIcu)->count();
        $dischargesToday = (int) $db->table('picupatients')->whereDate('DISDATE', $today)->whereRaw($this->nonIcu)->count();
        $activeConsults = (int) $db->table('consultations')->whereNull('signoff_date')->count();
        $deathsMonth = (int) $db->table('picupatients')->where('MORTALITY', 'Dead')
            ->whereBetween('DISDATE', [$monthStart, $today])->count();

        // hospitalist capacity utilisation (active ward / (hospitalist consultants x max_hospitalist))
        $hospitalistCount = (int) $db->table('members')
            ->where('position', 3)->where('active', 1)->where('on_service', 1)->where('specialty_id', 1)->count();
        $capacity = max(1, $hospitalistCount * $maxHospitalist);
        $occupancy = round(min(100, $activeWard / $capacity * 100), 1);

        // --- 30-day admissions vs discharges (non-ICU) ---
        $start30 = Carbon::today()->subDays(29)->toDateString();
        $admBy = $db->table('picupatients')->selectRaw('ADMDATE d, COUNT(*) c')
            ->whereBetween('ADMDATE', [$start30, $today])->whereRaw($this->nonIcu)
            ->groupBy('ADMDATE')->pluck('c', 'd');
        $disBy = $db->table('picupatients')->selectRaw('DISDATE d, COUNT(*) c')
            ->whereBetween('DISDATE', [$start30, $today])->whereRaw($this->nonIcu)
            ->groupBy('DISDATE')->pluck('c', 'd');
        $trend = ['labels' => [], 'admissions' => [], 'discharges' => []];
        for ($i = 29; $i >= 0; $i--) {
            $d = Carbon::today()->subDays($i)->toDateString();
            $trend['labels'][] = $d;
            $trend['admissions'][] = (int) ($admBy[$d] ?? 0);
            $trend['discharges'][] = (int) ($disBy[$d] ?? 0);
        }

        // --- consultations vs sign-offs, last 6 months ---
        $cons = ['labels' => [], 'new' => [], 'signed' => []];
        for ($i = 5; $i >= 0; $i--) {
            $m = Carbon::today()->subMonths($i);
            $s = $m->copy()->startOfMonth()->toDateString();
            $e = $m->copy()->endOfMonth()->toDateString();
            $cons['labels'][] = $m->format('M');
            $cons['new'][] = (int) $db->table('consultations')->whereBetween('consultation_date', [$s, $e])->count();
            $cons['signed'][] = (int) $db->table('consultations')->whereBetween('signoff_date', [$s, $e])->count();
        }

        // --- LOS distribution (discharged this year, non-ICU) ---
        $losRows = $db->table('picupatients')
            ->selectRaw('DATEDIFF(DISDATE, ADMDATE) los')
            ->whereNotNull('DISDATE')->whereNotNull('ADMDATE')
            ->whereBetween('DISDATE', [$yearStart, $today])->whereRaw($this->nonIcu)
            ->whereRaw('DATEDIFF(DISDATE, ADMDATE) >= 0')
            ->pluck('los');
        $losBuckets = ['0–2' => 0, '3–5' => 0, '6–10' => 0, '11–20' => 0, '21+' => 0];
        foreach ($losRows as $l) {
            $l = (int) $l;
            if ($l <= 2) $losBuckets['0–2']++;
            elseif ($l <= 5) $losBuckets['3–5']++;
            elseif ($l <= 10) $losBuckets['6–10']++;
            elseif ($l <= 20) $losBuckets['11–20']++;
            else $losBuckets['21+']++;
        }

        // --- specialty mix of the current census (non-ICU) ---
        $mix = $db->table('picupatients as p')->leftJoin('members as m', 'm.member_id', '=', 'p.consultant_id')
            ->selectRaw("
                SUM(CASE WHEN m.specialty_id = 1 AND (p.longterm IS NULL OR p.longterm = '') THEN 1 ELSE 0 END) hosp,
                SUM(CASE WHEN (m.specialty_id <> 1 OR m.specialty_id IS NULL) AND (p.longterm IS NULL OR p.longterm = '') THEN 1 ELSE 0 END) subs,
                SUM(CASE WHEN p.longterm = 'longterm' THEN 1 ELSE 0 END) longterm")
            ->whereNull('p.DISDATE')->whereRaw('(p.current_location <> "ICU" OR p.current_location IS NULL)')
            ->first();

        // --- top consultants by active load (non-ICU) ---
        $perConsultant = $db->table('picupatients as p')->join('members as m', 'm.member_id', '=', 'p.consultant_id')
            ->selectRaw('m.full_name name, COUNT(*) c')
            ->whereNull('p.DISDATE')->whereRaw('(p.current_location <> "ICU" OR p.current_location IS NULL)')
            ->groupBy('m.full_name')->orderByDesc('c')->limit(8)->get();

        // --- recent admissions ---
        $recent = $db->table('picupatients as p')->leftJoin('members as m', 'm.member_id', '=', 'p.consultant_id')
            ->selectRaw('p.PNAME name, p.MRN mrn, p.ADMDATE admitted, p.current_location loc, m.full_name consultant')
            ->whereNull('p.DISDATE')->orderByDesc('p.ID')->limit(8)->get();

        return Inertia::render('Dashboard', [
            'kpis' => [
                'census' => $active,
                'ward' => $activeWard,
                'icu' => $activeIcu,
                'admissionsToday' => $admissionsToday,
                'dischargesToday' => $dischargesToday,
                'activeConsults' => $activeConsults,
                'deathsMonth' => $deathsMonth,
                'occupancy' => $occupancy,
            ],
            'trend' => $trend,
            'consults' => $cons,
            'los' => ['labels' => array_keys($losBuckets), 'data' => array_values($losBuckets)],
            'mix' => [
                'hospitalist' => (int) ($mix->hosp ?? 0),
                'subspecialty' => (int) ($mix->subs ?? 0),
                'longterm' => (int) ($mix->longterm ?? 0),
            ],
            'perConsultant' => $perConsultant,
            'recent' => $recent,
            'generatedAt' => now()->format('D, d M Y · H:i'),
        ]);
    }
}
