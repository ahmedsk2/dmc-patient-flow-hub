<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 4 — Item 5: admin report of admission diagnosis codes with no matching icd10 reference row
 * ("orphan codes"). These were imported before ICD-10 validation was enforced; the report is
 * read-only (no bulk-fix tooling — that is a manual clinical decision / Phase 5). Soft-deleted
 * admissions are excluded.
 */
class OrphanDiagnosesController extends Controller
{
    public function index(): Response
    {
        $orphans = DB::table('admission_diagnoses as ad')
            ->leftJoin('icd10', 'icd10.code', '=', 'ad.icd10_code')
            ->join('admissions as a', 'a.id', '=', 'ad.admission_id')
            ->whereNull('icd10.code')
            ->whereNull('a.deleted_at')   // Phase 4 — Item 1: skip soft-deleted admissions
            ->selectRaw('ad.icd10_code, COUNT(DISTINCT ad.admission_id) admissions, MAX(a.admit_date) last_seen')
            ->groupBy('ad.icd10_code')
            ->orderByDesc('admissions')
            ->get();

        return Inertia::render('Admin/OrphanDiagnoses', [
            'orphans' => $orphans,
        ]);
    }
}
