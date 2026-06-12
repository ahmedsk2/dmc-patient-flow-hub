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
            ->leftJoin('users as cons', 'cons.id', '=', 'a.consultant_id')
            ->whereNotNull('a.discharge_date')->whereDate('a.discharge_date', '>=', $cutoff)
            // NULL-typed closed rows are REAL discharges too (legacy rows closed without a
            // transfer_type) — a bare whereNotIn silently drops them (J1-8)
            ->where(fn ($w) => $w
                ->whereNotIn('a.transfer_type', ['other transfer', 'transfer to other speciality', 'Transfer from ICU'])
                ->orWhereNull('a.transfer_type'))
            ->orderByDesc('a.discharge_date')->orderByDesc('a.id')->limit(100)
            ->get(['a.id', 'p.name', 'p.mrn', 'a.admit_date', 'a.discharge_date', 'a.discharge_to', 'a.outcome', 'a.current_location',
                DB::raw('DATEDIFF(a.discharge_date, a.admit_date) as los'),
                DB::raw('COALESCE(u.full_name, u.name) as actor'),
                DB::raw('COALESCE(adm.full_name, adm.name) as admitter'),
                // per-consultant grouping like the legacy "Dr X Patient List" sections (J1-8)
                DB::raw('COALESCE(cons.full_name, cons.name) as consultant')]);   // non-colliding aliases

        // diagnosis names for all listed discharges — ONE lookup, joined per admission in seq order
        $dxByAdmission = DB::table('admission_diagnoses as ad')
            ->leftJoin('icd10 as i', 'i.code', '=', 'ad.icd10_code')
            ->whereIn('ad.admission_id', $discharges->pluck('id'))
            ->orderBy('ad.admission_id')->orderBy('ad.seq')
            ->get(['ad.admission_id', DB::raw('COALESCE(i.name, ad.icd10_code) as dx')])
            ->groupBy('admission_id');
        $settings = \App\Models\Setting::current();
        $discharges->each(function ($d) use ($dxByAdmission, $today, $settings) {
            $d->diagnoses = ($dxByAdmission[$d->id] ?? collect())->pluck('dx')->implode('; ');
            $d->reversible = $d->discharge_date === $today;   // undo is same-day only (legacy)
            // same settings-driven LOS bands as the board cards (J2-7)
            $d->los_band = \App\Models\Admission::losBand($d->los === null ? null : (int) $d->los, $settings);
        });

        // sign-offs carry the legacy 48consultation.php columns: age, indications (reason names),
        // consultation date, consulted-by (consultation_from) and entered-by (J1-8)
        $reasons = \App\Models\ConsultationReason::pluck('name', 'id');
        $signoffs = DB::table('consultations as c')
            ->leftJoin('users as u', 'u.id', '=', 'c.consultant_id')
            ->leftJoin('users as eb', 'eb.id', '=', 'c.entered_by')
            ->whereNotNull('c.signoff_date')->whereDate('c.signoff_date', '>=', $cutoff)
            ->orderByDesc('c.signoff_date')->orderByDesc('c.id')->limit(100)
            ->get(['c.id', 'c.patient_name as name', 'c.mrn', 'c.age', 'c.indication',
                'c.consultation_date', 'c.consultation_from', 'c.signoff_date', 'c.to_service',
                DB::raw('COALESCE(u.full_name, u.name) as consultant'),
                DB::raw('COALESCE(eb.full_name, eb.name) as entered_by')]);   // non-colliding aliases
        $signoffs->each(function ($s) use ($today, $reasons) {
            $s->reversible = $s->signoff_date === $today;   // same-day only (legacy)
            // decode the indication JSON ids -> reason names (both int- and string-typed ids)
            $s->reasons = collect(json_decode($s->indication ?? '', true) ?: [])
                ->map(fn ($id) => $reasons[(int) $id] ?? null)->filter()->values()->all();
            unset($s->indication);
        });

        return Inertia::render('Recent/Index', [
            'discharges' => $discharges,
            'signoffs' => $signoffs,
            'since' => $cutoff,
        ]);
    }
}
