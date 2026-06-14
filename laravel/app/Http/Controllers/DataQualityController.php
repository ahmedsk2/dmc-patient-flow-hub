<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 4 — Item 6: admin data-quality dashboard. Five canary queries surface dirty data so the
 * clinical coordinator has a daily hygiene checklist. Read-only; each row links to the patient
 * board. Soft-deleted admissions are excluded throughout. The DataQualityNotify command reuses
 * checks() for its daily digest so the dashboard and the notification can never drift.
 */
class DataQualityController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/DataQuality', $this->checks() + [
            'longLos' => (int) (Setting::current()->long_los ?? 11),
            'multiplier' => (int) (Setting::current()->dq_los_multiplier ?? 2),
        ]);
    }

    /**
     * The five canary result sets, each capped at 50 rows. Returned as a plain array so both the
     * dashboard and the scheduled notifier consume the SAME queries.
     *
     * @return array{overLos: \Illuminate\Support\Collection, noDx: ..., badDates: ..., orphanDx: ..., doubleOpen: ...}
     */
    public function checks(): array
    {
        $longLos = (int) (Setting::current()->long_los ?? 11);
        $multiplier = max(1, (int) (Setting::current()->dq_los_multiplier ?? 2));
        $threshold = $longLos * $multiplier;

        // Q1: active non-long-term episodes with LOS > long_los × multiplier
        $overLos = DB::table('admissions as a')
            ->join('patients as p', 'p.id', '=', 'a.patient_id')
            ->whereNull('a.discharge_date')->where('a.is_longterm', 0)->whereNull('a.deleted_at')
            ->whereRaw('DATEDIFF(CURDATE(), a.admit_date) > ?', [$threshold])
            ->selectRaw('a.id, p.mrn, p.name, DATEDIFF(CURDATE(), a.admit_date) los, a.admit_date')
            ->orderByDesc('los')->limit(50)->get();

        // Q2: active episodes with zero diagnoses
        $noDx = DB::table('admissions as a')
            ->join('patients as p', 'p.id', '=', 'a.patient_id')
            ->leftJoin('admission_diagnoses as ad', 'ad.admission_id', '=', 'a.id')
            ->whereNull('a.discharge_date')->whereNull('a.deleted_at')
            ->whereNull('ad.id')
            ->selectRaw('a.id, p.mrn, p.name, a.admit_date')
            ->orderBy('a.admit_date')->limit(50)->get();

        // Q3: discharge_date < admit_date OR future admit_date
        $badDates = DB::table('admissions as a')
            ->join('patients as p', 'p.id', '=', 'a.patient_id')
            ->whereNull('a.deleted_at')
            ->where(fn ($w) => $w
                ->whereRaw('a.discharge_date IS NOT NULL AND a.discharge_date < a.admit_date')
                ->orWhereRaw('a.admit_date > CURDATE()'))
            ->selectRaw('a.id, p.mrn, p.name, a.admit_date, a.discharge_date')
            ->limit(50)->get();

        // Q4: diagnosis codes not in icd10 (orphan codes on non-deleted episodes)
        $orphanDx = DB::table('admission_diagnoses as ad')
            ->join('admissions as a', 'a.id', '=', 'ad.admission_id')
            ->join('patients as p', 'p.id', '=', 'a.patient_id')
            ->leftJoin('icd10', 'icd10.code', '=', 'ad.icd10_code')
            ->whereNull('icd10.code')->whereNull('a.deleted_at')
            ->selectRaw('a.id, p.mrn, p.name, ad.icd10_code')
            ->limit(50)->get();

        // Q5: patients with >1 simultaneously-open episode (data-integrity canary)
        $doubleOpen = DB::table('admissions as a')
            ->join('patients as p', 'p.id', '=', 'a.patient_id')
            ->join('admissions as b', fn ($j) => $j
                ->on('b.patient_id', '=', 'a.patient_id')
                ->whereColumn('b.id', '>', 'a.id')
                ->whereNull('b.discharge_date')
                ->whereNull('b.deleted_at'))
            ->whereNull('a.discharge_date')->whereNull('a.deleted_at')
            ->selectRaw('MIN(a.id) id, p.mrn, p.name, COUNT(DISTINCT a.id) + 1 open_episodes')
            ->groupBy('p.id', 'p.mrn', 'p.name')
            ->limit(50)->get();

        return compact('overLos', 'noDx', 'badDates', 'orphanDx', 'doubleOpen');
    }
}
