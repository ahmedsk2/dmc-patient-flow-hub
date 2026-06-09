<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Dashboard metrics — now reading the NEW clean schema (admissions/patients/consultations/users),
 * populated by `php artisan legacy:import`. Metric definitions follow docs/METRICS.md
 * (non-ICU = current_location <> 'ICU' OR NULL; consultations by consultation_date, sign-offs by
 * signoff_date). The JSON shape is unchanged from Phase 1, so the Vue layer is untouched.
 */
class DashboardController extends Controller
{
    private string $nonIcu = "(current_location <> 'ICU' OR current_location IS NULL)";

    public function index(): Response
    {
        $today = Carbon::today()->toDateString();
        $monthStart = Carbon::today()->startOfMonth()->toDateString();
        $yearStart = Carbon::today()->startOfYear()->toDateString();

        $maxHospitalist = (int) (Setting::current()->max_hospitalist ?? 30);

        $active = (int) DB::table('admissions')->whereNull('discharge_date')->count();
        $activeIcu = (int) DB::table('admissions')->whereNull('discharge_date')->where('current_location', 'ICU')->count();
        $activeWard = $active - $activeIcu;

        $admissionsToday = (int) DB::table('admissions')->whereDate('admit_date', $today)->whereRaw($this->nonIcu)->count();
        $dischargesToday = (int) DB::table('admissions')->whereDate('discharge_date', $today)->whereRaw($this->nonIcu)->count();
        $activeConsults = (int) DB::table('consultations')->whereNull('signoff_date')->count();
        $deathsMonth = (int) DB::table('admissions')->where('outcome', 'Dead')->whereBetween('discharge_date', [$monthStart, $today])->count();

        $hospitalistCount = (int) DB::table('users')->where('role', 3)->where('active', 1)->where('on_service', 1)->where('specialty_id', 1)->count();
        $capacity = max(1, $hospitalistCount * $maxHospitalist);
        $occupancy = round(min(100, $activeWard / $capacity * 100), 1);

        // 30-day admissions vs discharges (non-ICU)
        $start30 = Carbon::today()->subDays(29)->toDateString();
        $admBy = DB::table('admissions')->selectRaw('admit_date d, COUNT(*) c')->whereBetween('admit_date', [$start30, $today])->whereRaw($this->nonIcu)->groupBy('admit_date')->pluck('c', 'd');
        $disBy = DB::table('admissions')->selectRaw('discharge_date d, COUNT(*) c')->whereBetween('discharge_date', [$start30, $today])->whereRaw($this->nonIcu)->groupBy('discharge_date')->pluck('c', 'd');
        $trend = ['labels' => [], 'admissions' => [], 'discharges' => []];
        for ($i = 29; $i >= 0; $i--) {
            $d = Carbon::today()->subDays($i)->toDateString();
            $trend['labels'][] = $d;
            $trend['admissions'][] = (int) ($admBy[$d] ?? 0);
            $trend['discharges'][] = (int) ($disBy[$d] ?? 0);
        }

        // consultations vs sign-offs (6 months)
        $cons = ['labels' => [], 'new' => [], 'signed' => []];
        for ($i = 5; $i >= 0; $i--) {
            $m = Carbon::today()->subMonths($i);
            $s = $m->copy()->startOfMonth()->toDateString();
            $e = $m->copy()->endOfMonth()->toDateString();
            $cons['labels'][] = $m->format('M');
            $cons['new'][] = (int) DB::table('consultations')->whereBetween('consultation_date', [$s, $e])->count();
            $cons['signed'][] = (int) DB::table('consultations')->whereBetween('signoff_date', [$s, $e])->count();
        }

        // LOS distribution (discharged this year, non-ICU)
        $losRows = DB::table('admissions')->selectRaw('DATEDIFF(discharge_date, admit_date) los')
            ->whereNotNull('discharge_date')->whereNotNull('admit_date')->whereBetween('discharge_date', [$yearStart, $today])
            ->whereRaw($this->nonIcu)->whereRaw('DATEDIFF(discharge_date, admit_date) >= 0')->pluck('los');
        $losBuckets = ['0–2' => 0, '3–5' => 0, '6–10' => 0, '11–20' => 0, '21+' => 0];
        foreach ($losRows as $l) {
            $l = (int) $l;
            if ($l <= 2) $losBuckets['0–2']++; elseif ($l <= 5) $losBuckets['3–5']++;
            elseif ($l <= 10) $losBuckets['6–10']++; elseif ($l <= 20) $losBuckets['11–20']++; else $losBuckets['21+']++;
        }

        // census by service (active non-ICU)
        $mix = DB::table('admissions as a')->leftJoin('users as u', 'u.id', '=', 'a.consultant_id')
            ->selectRaw("
                SUM(CASE WHEN u.specialty_id = 1 AND a.is_longterm = 0 THEN 1 ELSE 0 END) hosp,
                SUM(CASE WHEN (u.specialty_id <> 1 OR u.specialty_id IS NULL) AND a.is_longterm = 0 THEN 1 ELSE 0 END) subs,
                SUM(CASE WHEN a.is_longterm = 1 THEN 1 ELSE 0 END) longterm")
            ->whereNull('a.discharge_date')->whereRaw('(a.current_location <> "ICU" OR a.current_location IS NULL)')->first();

        $perConsultant = DB::table('admissions as a')->join('users as u', 'u.id', '=', 'a.consultant_id')
            ->selectRaw('COALESCE(u.full_name, u.name) name, COUNT(*) c')
            ->whereNull('a.discharge_date')->whereRaw('(a.current_location <> "ICU" OR a.current_location IS NULL)')
            ->groupByRaw('COALESCE(u.full_name, u.name)')->orderByDesc('c')->limit(8)->get();

        // per-consultant breakdown of the active census (legacy "Patient count per consultant")
        $tbExists = "EXISTS (SELECT 1 FROM admission_diagnoses ad JOIN tb_diagnoses tb ON tb.icd10_code = ad.icd10_code WHERE ad.admission_id = a.id)";
        $consultantBoard = DB::table('admissions as a')->join('users as u', 'u.id', '=', 'a.consultant_id')
            ->whereNull('a.discharge_date')->where('u.active', 1)
            ->selectRaw("COALESCE(u.full_name, u.name) name, u.on_service, u.specialty_id,
                SUM(CASE WHEN a.is_new_assignment = 1 THEN 1 ELSE 0 END) new,
                SUM(CASE WHEN a.is_new_assignment = 1 THEN 0 ELSE 1 END) old,
                SUM(CASE WHEN a.current_location = 'ICU' THEN 1 ELSE 0 END) icu,
                SUM(CASE WHEN (a.current_location <> 'ICU' OR a.current_location IS NULL) THEN 1 ELSE 0 END) ward,
                SUM(CASE WHEN {$tbExists} THEN 1 ELSE 0 END) tb,
                SUM(CASE WHEN (a.current_location <> 'ICU' OR a.current_location IS NULL) AND a.medical_discharge_date IS NULL AND a.is_longterm = 0 AND NOT {$tbExists} THEN 1 ELSE 0 END) active,
                COUNT(*) total")
            ->groupByRaw('COALESCE(u.full_name, u.name), u.on_service, u.specialty_id')
            ->orderByDesc('total')->get()
            ->map(fn ($r) => [
                'name' => $r->name, 'on_service' => (bool) $r->on_service, 'specialty_id' => (int) $r->specialty_id,
                'new' => (int) $r->new, 'old' => (int) $r->old, 'icu' => (int) $r->icu, 'ward' => (int) $r->ward,
                'tb' => (int) $r->tb, 'active' => (int) $r->active, 'total' => (int) $r->total,
            ]);

        $recent = DB::table('admissions as a')->join('patients as p', 'p.id', '=', 'a.patient_id')
            ->leftJoin('users as u', 'u.id', '=', 'a.consultant_id')
            ->selectRaw('p.name name, p.mrn mrn, a.admit_date admitted, a.current_location loc, COALESCE(u.full_name, u.name) consultant')
            ->whereNull('a.discharge_date')->orderByDesc('a.id')->limit(8)->get();

        // top diagnoses among admissions in the last 7 days
        $weekStart = Carbon::today()->subDays(6)->toDateString();
        $topDxWeek = DB::table('admission_diagnoses as ad')->join('admissions as a', 'a.id', '=', 'ad.admission_id')
            ->leftJoin('icd10 as i', 'i.code', '=', 'ad.icd10_code')
            ->whereBetween('a.admit_date', [$weekStart, $today])
            ->selectRaw('ad.icd10_code code, MAX(i.name) name, COUNT(*) c')
            ->groupBy('ad.icd10_code')->orderByDesc('c')->limit(6)->get()
            ->map(fn ($r) => ['name' => $r->name ?: $r->code, 'count' => (int) $r->c]);

        return Inertia::render('Dashboard', [
            'kpis' => [
                'census' => $active, 'ward' => $activeWard, 'icu' => $activeIcu,
                'admissionsToday' => $admissionsToday, 'dischargesToday' => $dischargesToday,
                'activeConsults' => $activeConsults, 'deathsMonth' => $deathsMonth, 'occupancy' => $occupancy,
            ],
            'trend' => $trend,
            'consults' => $cons,
            'los' => ['labels' => array_keys($losBuckets), 'data' => array_values($losBuckets)],
            'mix' => ['hospitalist' => (int) ($mix->hosp ?? 0), 'subspecialty' => (int) ($mix->subs ?? 0), 'longterm' => (int) ($mix->longterm ?? 0)],
            'perConsultant' => $perConsultant,
            'consultantBoard' => $consultantBoard,
            'topDxWeek' => $topDxWeek,
            'recent' => $recent,
            'generatedAt' => now()->format('D, d M Y · H:i'),
        ]);
    }
}
