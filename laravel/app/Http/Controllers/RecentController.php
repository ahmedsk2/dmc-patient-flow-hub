<?php

namespace App\Http\Controllers;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Recent-activity registries (admin): YESTERDAY + TODAY's discharges and consultation sign-offs
 * (legacy 48discharge.php window: `DISDATE + INTERVAL 1 DAY >= today`), each with an undo control.
 * Undo posts to the admin-only reverse endpoints, which accept SAME-DAY rows only (legacy showed
 * the undo button only while the date was still today) — `reversible` drives the button.
 */
class RecentController extends Controller
{
    public function index(): Response
    {
        $cutoff = Carbon::yesterday()->toDateString();
        $today = Carbon::today()->toDateString();

        $discharges = DB::table('admissions as a')
            ->join('patients as p', 'p.id', '=', 'a.patient_id')
            ->leftJoin('users as u', 'u.id', '=', 'a.discharged_by')
            ->leftJoin('users as adm', 'adm.id', '=', 'a.admitted_by')
            ->whereNotNull('a.discharge_date')->whereDate('a.discharge_date', '>=', $cutoff)
            ->whereNotIn('a.transfer_type', ['other transfer', 'transfer to other speciality', 'Transfer from ICU'])
            ->orderByDesc('a.discharge_date')->orderByDesc('a.id')->limit(100)
            ->get(['a.id', 'p.name', 'p.mrn', 'a.admit_date', 'a.discharge_date', 'a.discharge_to', 'a.outcome', 'a.current_location',
                DB::raw('DATEDIFF(a.discharge_date, a.admit_date) as los'),
                DB::raw('COALESCE(u.full_name, u.name) as actor'),
                DB::raw('COALESCE(adm.full_name, adm.name) as admitter')]);   // non-colliding alias (see 'consultant')

        // diagnosis names for all listed discharges — ONE lookup, joined per admission in seq order
        $dxByAdmission = DB::table('admission_diagnoses as ad')
            ->leftJoin('icd10 as i', 'i.code', '=', 'ad.icd10_code')
            ->whereIn('ad.admission_id', $discharges->pluck('id'))
            ->orderBy('ad.admission_id')->orderBy('ad.seq')
            ->get(['ad.admission_id', DB::raw('COALESCE(i.name, ad.icd10_code) as dx')])
            ->groupBy('admission_id');
        $discharges->each(function ($d) use ($dxByAdmission, $today) {
            $d->diagnoses = ($dxByAdmission[$d->id] ?? collect())->pluck('dx')->implode('; ');
            $d->reversible = $d->discharge_date === $today;   // undo is same-day only (legacy)
        });

        $signoffs = DB::table('consultations as c')
            ->leftJoin('users as u', 'u.id', '=', 'c.consultant_id')
            ->whereNotNull('c.signoff_date')->whereDate('c.signoff_date', '>=', $cutoff)
            ->orderByDesc('c.signoff_date')->orderByDesc('c.id')->limit(100)
            ->get(['c.id', 'c.patient_name as name', 'c.mrn', 'c.signoff_date', 'c.to_service',
                DB::raw('COALESCE(u.full_name, u.name) as consultant')]);
        $signoffs->each(fn ($s) => $s->reversible = $s->signoff_date === $today);   // same-day only

        return Inertia::render('Recent/Index', [
            'discharges' => $discharges,
            'signoffs' => $signoffs,
            'since' => $cutoff,
        ]);
    }
}
