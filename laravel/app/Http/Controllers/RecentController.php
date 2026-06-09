<?php

namespace App\Http\Controllers;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Recent-activity registries (admin): same-day / ≤48h discharges and consultation sign-offs, each
 * with an undo control (mirrors the legacy 48discharge.php / 48consultation.php). Undo posts to the
 * existing admin-only reverse-discharge / reverse-signoff endpoints.
 */
class RecentController extends Controller
{
    public function index(): Response
    {
        $cutoff = Carbon::today()->subDays(2)->toDateString();

        $discharges = DB::table('admissions as a')
            ->join('patients as p', 'p.id', '=', 'a.patient_id')
            ->leftJoin('users as u', 'u.id', '=', 'a.discharged_by')
            ->whereNotNull('a.discharge_date')->whereDate('a.discharge_date', '>=', $cutoff)
            ->whereNotIn('a.transfer_type', ['other transfer', 'transfer to other speciality', 'Transfer from ICU'])
            ->orderByDesc('a.discharge_date')->orderByDesc('a.id')->limit(100)
            ->get(['a.id', 'p.name', 'p.mrn', 'a.discharge_date', 'a.outcome', 'a.current_location',
                DB::raw('COALESCE(u.full_name, u.name) as actor')]);

        $signoffs = DB::table('consultations as c')
            ->leftJoin('users as u', 'u.id', '=', 'c.consultant_id')
            ->whereNotNull('c.signoff_date')->whereDate('c.signoff_date', '>=', $cutoff)
            ->orderByDesc('c.signoff_date')->orderByDesc('c.id')->limit(100)
            ->get(['c.id', 'c.patient_name as name', 'c.mrn', 'c.signoff_date', 'c.to_service',
                DB::raw('COALESCE(u.full_name, u.name) as consultant')]);

        return Inertia::render('Recent/Index', [
            'discharges' => $discharges,
            'signoffs' => $signoffs,
            'since' => $cutoff,
        ]);
    }
}
