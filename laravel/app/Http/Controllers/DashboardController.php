<?php

namespace App\Http\Controllers;

use App\Models\Admission;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
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
    private string $nonIcu = \App\Models\Admission::NON_ICU_SQL;

    public function index(): Response
    {
        $today = Carbon::today()->toDateString();
        $monthStart = Carbon::today()->startOfMonth()->toDateString();
        $yearStart = Carbon::today()->startOfYear()->toDateString();

        $settings = Setting::current();
        $wardBeds = max(1, (int) ($settings->ward_beds ?? 50));   // licensed ward (non-ICU) beds — set in Control
        $icuBeds = max(0, (int) ($settings->icu_beds ?? 10));     // licensed ICU beds — set in Control (drives the OccupancyTracker ICU band)

        // Phase 4 — Item 1: every raw admissions/consultations analytic excludes soft-deleted rows.
        $active = (int) DB::table('admissions')->whereNull('discharge_date')->whereNull('deleted_at')->count();
        $activeIcu = (int) DB::table('admissions')->whereNull('discharge_date')->where('current_location', 'ICU')->whereNull('deleted_at')->count();
        $activeWard = $active - $activeIcu;

        $admissionsToday = (int) DB::table('admissions')->whereDate('admit_date', $today)->whereRaw($this->nonIcu)->whereNull('deleted_at')->count();
        $dischargesToday = (int) DB::table('admissions')->whereDate('discharge_date', $today)->whereRaw($this->nonIcu)->whereNull('deleted_at')->count();
        $activeConsults = (int) DB::table('consultations')->whereNull('signoff_date')->whereNull('deleted_at')->count();
        // consultation donut = [signed-off in the last 24h, active] — legacy dashboard/1.php
        // (`signoff_date + INTERVAL 1 DAY >= CURDATE()`, i.e. yesterday + today) (J2-5)
        $signed24h = (int) DB::table('consultations')->where('signoff_date', '>=', Carbon::yesterday()->toDateString())->whereNull('deleted_at')->count();
        $deathsMonth = (int) DB::table('admissions')->where('outcome', 'Dead')->whereBetween('discharge_date', [$monthStart, $today])->whereNull('deleted_at')->count();

        // ── Boarding (Phase 1, Item 1) ─────────────────────────────────────────────────────────
        // medically cleared but the bed is still occupied — the "Disch. still in" board badge
        // (medical_discharge_date IS NOT NULL AND discharge_date IS NULL) promoted to a KPI.
        // delay_days = DATEDIFF(today, medical_discharge_date) = days since clearance (NOT lengthOfStay,
        // which measures from admit). LIVE tier — never cached, must reflect the last action.
        $boardingCount = (int) DB::table('admissions')
            ->whereNull('discharge_date')->whereNotNull('medical_discharge_date')->whereNull('deleted_at')->count();
        $boardingWorklist = DB::table('admissions as a')
            ->join('patients as p', 'p.id', '=', 'a.patient_id')
            ->leftJoin('users as u', 'u.id', '=', 'a.consultant_id')
            ->whereNull('a.discharge_date')->whereNotNull('a.medical_discharge_date')->whereNull('a.deleted_at')
            ->selectRaw('a.id, p.name, p.mrn, a.medical_discharge_date,
                         DATEDIFF(CURDATE(), a.medical_discharge_date) delay_days,
                         COALESCE(u.full_name, u.name) consultant')
            ->orderByDesc('delay_days')->orderBy('a.id')->limit(10)->get()
            ->map(fn ($r) => [
                'id' => (int) $r->id, 'name' => $r->name, 'mrn' => $r->mrn,
                'delay_days' => (int) $r->delay_days, 'consultant' => $r->consultant ?: 'Unassigned',
            ]);

        // current-month average LOS over non-ICU discharges — the Statistics avgLos formula family
        // (AVG(DATEDIFF) with the >= 0 guard), month-scoped like deathsMonth above; 2 decimals
        // like the legacy dashboard figure (J2-6)
        $avgLosMonth = round((float) (DB::table('admissions')
            ->whereBetween('discharge_date', [$monthStart, $today])->whereNotNull('admit_date')
            ->whereRaw($this->nonIcu)->whereNull('deleted_at')->whereRaw('DATEDIFF(discharge_date, admit_date) >= 0')
            ->selectRaw('AVG(DATEDIFF(discharge_date, admit_date)) v')->value('v') ?? 0), 2);

        // real bed occupancy: active WARD (non-ICU) patients ÷ licensed ward beds. True % may exceed
        // 100 (over-census); a separate capped value drives the radial gauge arc.
        $occupancy = round($activeWard / $wardBeds * 100, 1);
        $occupancyGauge = min(100, $occupancy);

        // ── Heavy aggregations (Phase 1, Item 7) ───────────────────────────────────────────────
        // Chart data + historical aggregations that change slowly: cached for 5 min (= the Vue
        // auto-refresh interval) and busted on every patient-flow mutation by PatientActionController.
        // Live KPIs (census, today's counts, boarding, occupancy, recent, myUnit, alerts) stay OUTSIDE.
        // $admBy/$disBy are returned out of the cache because the Item-2 delta block consumes them.
        $nonIcu = $this->nonIcu;
        $heavy = Cache::remember(\App\Support\DashboardCache::KEY, 300, function () use ($today, $monthStart, $yearStart, $nonIcu) {
        // 31-point admissions-vs-discharges trend: today-30 .. today INCLUSIVE, like the legacy
        // 31-day loop (dashboard/1.php) — non-ICU (J2-2)
        $start30 = Carbon::today()->subDays(30)->toDateString();
        $admBy = DB::table('admissions')->selectRaw('admit_date d, COUNT(*) c')->whereBetween('admit_date', [$start30, $today])->whereRaw($nonIcu)->whereNull('deleted_at')->groupBy('admit_date')->pluck('c', 'd')->all();   // ->all(): cache a plain array, not a Collection (survives the file/db cache serialize round-trip; a cached object came back as __PHP_Incomplete_Class on prod)
        $disBy = DB::table('admissions')->selectRaw('discharge_date d, COUNT(*) c')->whereBetween('discharge_date', [$start30, $today])->whereRaw($nonIcu)->whereNull('deleted_at')->groupBy('discharge_date')->pluck('c', 'd')->all();   // ->all(): plain array for cache round-trip safety (see admBy)
        $trend = ['labels' => [], 'admissions' => [], 'discharges' => []];
        for ($i = 30; $i >= 0; $i--) {
            $d = Carbon::today()->subDays($i)->toDateString();
            $trend['labels'][] = $d;
            $trend['admissions'][] = (int) ($admBy[$d] ?? 0);
            $trend['discharges'][] = (int) ($disBy[$d] ?? 0);
        }

        // consultations vs sign-offs — 6 calendar months including current (M-5 .. M), 2 queries
        // total (was a 12-query month loop). Same DATE_FORMAT('%Y-%m') GROUP BY + PHP-zip pattern
        // as the 31-day admissions trend above; the output shape (and numbers) are unchanged.
        $sixMonthsAgo = Carbon::today()->startOfMonth()->subMonths(5)->toDateString();
        $consByMonth = DB::table('consultations')
            ->selectRaw("DATE_FORMAT(consultation_date, '%Y-%m') ym, COUNT(*) c")
            ->where('consultation_date', '>=', $sixMonthsAgo)->whereNull('deleted_at')
            ->groupBy('ym')->pluck('c', 'ym');
        $signedByMonth = DB::table('consultations')
            ->selectRaw("DATE_FORMAT(signoff_date, '%Y-%m') ym, COUNT(*) c")
            ->where('signoff_date', '>=', $sixMonthsAgo)->whereNull('deleted_at')
            ->groupBy('ym')->pluck('c', 'ym');

        $cons = ['labels' => [], 'new' => [], 'signed' => []];
        for ($i = 5; $i >= 0; $i--) {
            $m = Carbon::today()->subMonths($i);
            $ym = $m->format('Y-m');
            $cons['labels'][] = $m->format('M');
            $cons['new'][] = (int) ($consByMonth[$ym] ?? 0);
            $cons['signed'][] = (int) ($signedByMonth[$ym] ?? 0);
        }

        // LOS distribution (discharged this year, non-ICU)
        $losRows = DB::table('admissions')->selectRaw('DATEDIFF(discharge_date, admit_date) los')
            ->whereNotNull('discharge_date')->whereNotNull('admit_date')->whereBetween('discharge_date', [$yearStart, $today])
            ->whereRaw($nonIcu)->whereNull('deleted_at')->whereRaw('DATEDIFF(discharge_date, admit_date) >= 0')->pluck('los');
        $losBuckets = ['0–2' => 0, '3–5' => 0, '6–10' => 0, '11–20' => 0, '21+' => 0];
        foreach ($losRows as $l) {
            $l = (int) $l;
            if ($l <= 2) $losBuckets['0–2']++; elseif ($l <= 5) $losBuckets['3–5']++;
            elseif ($l <= 10) $losBuckets['6–10']++; elseif ($l <= 20) $losBuckets['11–20']++; else $losBuckets['21+']++;
        }

        // census by service (active non-ICU, ASSIGNED only — the legacy donut INNER JOINed
        // members, so unassigned rows never counted; they live on the New Admissions queue)
        $mix = (array) DB::table('admissions as a')->join('users as u', 'u.id', '=', 'a.consultant_id')
            ->selectRaw("
                SUM(CASE WHEN u.specialty_id = 1 AND a.is_longterm = 0 THEN 1 ELSE 0 END) hosp,
                SUM(CASE WHEN (u.specialty_id <> 1 OR u.specialty_id IS NULL) AND a.is_longterm = 0 THEN 1 ELSE 0 END) subs,
                SUM(CASE WHEN a.is_longterm = 1 THEN 1 ELSE 0 END) longterm")
            ->whereNull('a.discharge_date')->whereNull('a.deleted_at')->whereRaw('(a.current_location <> "ICU" OR a.current_location IS NULL)')->first();

        // census-donut headline 'Current patients: N (incl. M TB)' — the DONUT'S OWN population
        // (assigned non-ICU active), i.e. the sum of the mix slices, with its TB subset counted
        // over the same rows (legacy dashboard/1.php:151-154 summed the donut buckets; the TB
        // query INNER JOINed members too). The Active Census KPI tile (kpis.census) stays all-active.
        $donutTotal = (int) (($mix['hosp'] ?? 0) + ($mix['subs'] ?? 0) + ($mix['longterm'] ?? 0));
        $donutTb = (int) DB::table('admissions as a')->join('users as u', 'u.id', '=', 'a.consultant_id')
            ->whereNull('a.discharge_date')->whereNull('a.deleted_at')->whereRaw('(a.current_location <> "ICU" OR a.current_location IS NULL)')
            ->whereExists(fn ($s) => $s->selectRaw('1')->from('admission_diagnoses as ad')
                ->join('tb_diagnoses as tb', 'tb.icd10_code', '=', 'ad.icd10_code')
                ->whereColumn('ad.admission_id', 'a.id'))->count();

        // alias must NOT collide with a real column (users.name): MySQL resolves GROUP BY to the
        // column (only_full_group_by error), MariaDB can't match repeated raw expressions either.
        // A unique alias resolves identically on both engines. Re-keyed below to keep the UI shape.
        // id + specialty_id added (Items 3 + 6): the load chart drill-through resolves a bar to a
        // consultant_id, and the load-fairness band picks min/max by specialty. u.id is the PK so
        // the GROUP BY stays well-formed on only_full_group_by (MySQL/MariaDB) when listed explicitly.
        $perConsultant = DB::table('admissions as a')->join('users as u', 'u.id', '=', 'a.consultant_id')
            ->selectRaw('u.id consultant_id, u.specialty_id, COALESCE(u.full_name, u.name) consultant, COUNT(*) c')
            ->whereNull('a.discharge_date')->whereNull('a.deleted_at')->whereRaw('(a.current_location <> "ICU" OR a.current_location IS NULL)')
            ->groupByRaw('u.id, u.specialty_id, consultant')->orderByDesc('c')->limit(8)->get()
            ->map(fn ($r) => ['id' => (int) $r->consultant_id, 'specialty_id' => (int) $r->specialty_id,
                'name' => $r->consultant, 'c' => (int) $r->c])->all();   // plain array of arrays — cache round-trip safe

        // per-consultant breakdown of the active census (legacy "Patient count per consultant").
        // USERS-driven (left join) so an on-service consultant with ZERO patients still appears
        // — like the legacy table built from the members list; off-service users only show
        // while they still hold patients. The SUM cases guard a.id IS NOT NULL so the empty
        // left-join row contributes nothing.
        $tbExists = "EXISTS (SELECT 1 FROM admission_diagnoses ad JOIN tb_diagnoses tb ON tb.icd10_code = ad.icd10_code WHERE ad.admission_id = a.id)";
        // "New" keys on the is_new_assignment FLAG (the managed legacy signal — set on assign /
        // handover / shuffle, cleared on discharge / reassign), NOT a rolling assigned_at >= now-24h
        // window: that window silently reads 0 on imported/snapshot data once the newest assignment is
        // >24h old, blanking the "New" column even though 27 patients are flagged. Matches the legacy
        // "Patient count per consultant" table (active + newassign=1).
        $consultantBoard = DB::table('users as u')
            ->leftJoin('admissions as a', fn ($j) => $j->on('u.id', '=', 'a.consultant_id')->whereNull('a.discharge_date')->whereNull('a.deleted_at'))
            ->where('u.active', 1)
            ->whereNull('u.deleted_at')   // Phase 4 — Item 1: a soft-deleted consultant drops off the board
            ->where(fn ($w) => $w
                ->where(fn ($w2) => $w2->where('u.on_service', 1)->where('u.role', \App\Models\User::ROLE_CONSULTANT))
                ->orWhereExists(fn ($s) => $s->selectRaw('1')->from('admissions as ax')
                    ->whereColumn('ax.consultant_id', 'u.id')->whereNull('ax.discharge_date')->whereNull('ax.deleted_at')))
            ->selectRaw("u.id, COALESCE(u.full_name, u.name) consultant, u.on_service, u.specialty_id,
                SUM(CASE WHEN a.id IS NOT NULL AND a.is_new_assignment = 1 THEN 1 ELSE 0 END) new,
                SUM(CASE WHEN a.id IS NOT NULL AND a.is_new_assignment = 0 THEN 1 ELSE 0 END) old,
                SUM(CASE WHEN a.current_location = 'ICU' THEN 1 ELSE 0 END) icu,
                SUM(CASE WHEN a.id IS NOT NULL AND (a.current_location <> 'ICU' OR a.current_location IS NULL) THEN 1 ELSE 0 END) ward,
                SUM(CASE WHEN a.id IS NOT NULL AND {$tbExists} THEN 1 ELSE 0 END) tb,
                SUM(CASE WHEN a.id IS NOT NULL AND (a.current_location <> 'ICU' OR a.current_location IS NULL) AND a.medical_discharge_date IS NULL AND a.is_longterm = 0 AND NOT {$tbExists} THEN 1 ELSE 0 END) active,
                COUNT(a.id) total")
            ->groupByRaw('u.id, consultant, u.on_service, u.specialty_id')
            ->orderByDesc('total')->get()
            ->map(fn ($r) => [
                'id' => (int) $r->id,
                'name' => $r->consultant, 'on_service' => (bool) $r->on_service, 'specialty_id' => (int) $r->specialty_id,
                'new' => (int) $r->new, 'old' => (int) $r->old, 'icu' => (int) $r->icu, 'ward' => (int) $r->ward,
                'tb' => (int) $r->tb, 'active' => (int) $r->active, 'total' => (int) $r->total,
            ])->all();   // plain array — cache round-trip safe

        // per-consultant activity since yesterday (legacy dashboard/1.php "last day" block).
        // DATE columns — compare against the yesterday date STRING, so "since yesterday" means
        // yesterday + today. Alias 'consultant' is the established non-colliding GROUP BY alias.
        // Legacy parity: non-ICU rows only, joined to ACTIVE CONSULTANTS (position=3, active=1).
        $since = Carbon::today()->subDay()->toDateString();
        $activeConsultant = fn ($q) => $q->where('u.role', \App\Models\User::ROLE_CONSULTANT)->where('u.active', 1);
        $adm24 = DB::table('admissions as a')->join('users as u', 'u.id', '=', 'a.consultant_id')
            ->where('a.admit_date', '>=', $since)->whereRaw($nonIcu)->whereNull('a.deleted_at')->tap($activeConsultant)
            ->selectRaw('COALESCE(u.full_name, u.name) consultant, COUNT(*) c')
            ->groupBy('consultant')->pluck('c', 'consultant');
        $dis24 = DB::table('admissions as a')->join('users as u', 'u.id', '=', 'a.consultant_id')
            ->where('a.discharge_date', '>=', $since)->whereRaw($nonIcu)->whereNull('a.deleted_at')->tap($activeConsultant)
            ->selectRaw('COALESCE(u.full_name, u.name) consultant, COUNT(*) c')
            ->groupBy('consultant')->pluck('c', 'consultant');
        // every ON-SERVICE active consultant appears, zeros included — the legacy table was
        // built from the members list, so a quiet consultant still shows a row (J2-3)
        $onServiceNames = DB::table('users')->where('role', \App\Models\User::ROLE_CONSULTANT)
            ->where('active', 1)->where('on_service', 1)
            ->selectRaw('COALESCE(full_name, name) consultant')->pluck('consultant');
        $activity24h = $adm24->keys()->merge($dis24->keys())->merge($onServiceNames)->unique()->sort()->values()
            ->map(fn ($name) => [
                'name' => $name,
                'admissions' => (int) ($adm24[$name] ?? 0),
                'discharges' => (int) ($dis24[$name] ?? 0),
            ])->all();   // plain array — cache round-trip safe

        // year-to-date counter strip (non-ICU admissions/discharges, per docs/METRICS.md)
        $ytd = [
            'admissions' => (int) DB::table('admissions')->whereBetween('admit_date', [$yearStart, $today])->whereRaw($nonIcu)->whereNull('deleted_at')->count(),
            'discharges' => (int) DB::table('admissions')->whereBetween('discharge_date', [$yearStart, $today])->whereRaw($nonIcu)->whereNull('deleted_at')->count(),
            'consultations' => (int) DB::table('consultations')->whereBetween('consultation_date', [$yearStart, $today])->whereNull('deleted_at')->count(),
            'signoffs' => (int) DB::table('consultations')->whereBetween('signoff_date', [$yearStart, $today])->whereNull('deleted_at')->count(),
        ];

        // top 5 diagnoses for THIS calendar week-number across ALL years — the legacy card
        // (dashboard/3.php: WEEK(ADMDATE) = WEEK(today)) is a seasonal "what does this week of
        // the year admit" view, not a rolling last-7-days window. DELIBERATE definition change
        // back to legacy (was: last 7 days).
        $topDxWeekNum = (int) DB::selectOne('SELECT WEEK(CURDATE()) wk')->wk;
        $topDxWeek = DB::table('admission_diagnoses as ad')->join('admissions as a', 'a.id', '=', 'ad.admission_id')
            ->leftJoin('icd10 as i', 'i.code', '=', 'ad.icd10_code')
            ->whereRaw('WEEK(a.admit_date) = WEEK(CURDATE())')->whereNull('a.deleted_at')   // Phase 4 — Item 1
            ->selectRaw('ad.icd10_code code, MAX(i.name) name, COUNT(*) c')
            ->groupBy('ad.icd10_code')->orderByDesc('c')->limit(5)->get()
            ->map(fn ($r) => ['name' => $r->name ?: $r->code, 'count' => (int) $r->c])->all();   // plain array — cache round-trip safe

            return compact('trend', 'cons', 'losBuckets', 'mix', 'donutTotal', 'donutTb',
                'perConsultant', 'consultantBoard', 'activity24h', 'ytd', 'topDxWeek', 'topDxWeekNum',
                'admBy', 'disBy');
        });   // end Cache::remember('dashboard.heavy')

        // unpack the heavy tier
        $trend = $heavy['trend']; $cons = $heavy['cons']; $losBuckets = $heavy['losBuckets'];
        $mix = $heavy['mix']; $donutTotal = $heavy['donutTotal']; $donutTb = $heavy['donutTb'];
        $perConsultant = $heavy['perConsultant']; $consultantBoard = $heavy['consultantBoard'];
        $activity24h = $heavy['activity24h']; $ytd = $heavy['ytd'];
        $topDxWeek = $heavy['topDxWeek']; $topDxWeekNum = $heavy['topDxWeekNum'];
        $admBy = $heavy['admBy']; $disBy = $heavy['disBy'];

        // ── Recent admissions (live tier) ──────────────────────────────────────────────────────
        // a simple ORDER BY DESC LIMIT 8 — kept OUTSIDE the cache so the newest admission always shows.
        $recent = DB::table('admissions as a')->join('patients as p', 'p.id', '=', 'a.patient_id')
            ->leftJoin('users as u', 'u.id', '=', 'a.consultant_id')
            ->selectRaw('p.name name, p.mrn mrn, a.admit_date admitted, a.current_location loc, COALESCE(u.full_name, u.name) consultant')
            ->whereNull('a.discharge_date')->whereNull('a.deleted_at')->orderByDesc('a.id')->limit(8)->get();

        // ── KPI period-over-period deltas (Phase 1, Item 2) ─────────────────────────────────────
        // {value, delta, direction} for the volatile KPIs. admissions/discharges reuse the in-memory
        // $admBy/$disBy maps (zero extra queries); deaths vs prior calendar month; occupancy vs the
        // prior-week point-in-time ward census. good_up flags drive the chip color (good/bad/neutral).
        $trailAdm = array_sum(array_map(
            fn ($i) => (int) ($admBy[Carbon::today()->subDays($i)->toDateString()] ?? 0), range(1, 7))) / 7;
        $trailDis = array_sum(array_map(
            fn ($i) => (int) ($disBy[Carbon::today()->subDays($i)->toDateString()] ?? 0), range(1, 7))) / 7;

        $priorMonthStart = Carbon::today()->subMonthNoOverflow()->startOfMonth()->toDateString();
        $priorMonthEnd = Carbon::today()->subMonthNoOverflow()->endOfMonth()->toDateString();
        $deathsPrior = (int) DB::table('admissions')->where('outcome', 'Dead')
            ->whereBetween('discharge_date', [$priorMonthStart, $priorMonthEnd])->whereNull('deleted_at')->count();

        // prior-week occupancy: point-in-time ward census exactly 7 days ago (single-query proxy for a
        // true rolling mean — clinically adequate, keeps the page fast). Promotable in Item 7 if needed.
        $priorWeekOccupancy = round((float) (DB::table('admissions')
            ->whereRaw('admit_date <= DATE_SUB(CURDATE(), INTERVAL 7 DAY)')
            ->whereRaw('(discharge_date IS NULL OR discharge_date > DATE_SUB(CURDATE(), INTERVAL 7 DAY))')
            ->whereRaw($nonIcu)->whereNull('deleted_at')->count()) / $wardBeds * 100, 1);

        $deltas = [
            'admissions' => [
                'value' => $admissionsToday, 'mean7' => round($trailAdm, 1),
                'delta' => round($admissionsToday - $trailAdm, 1),
                'direction' => $admissionsToday >= $trailAdm ? 'up' : 'down', 'good_up' => true,
            ],
            'discharges' => [
                'value' => $dischargesToday, 'mean7' => round($trailDis, 1),
                'delta' => round($dischargesToday - $trailDis, 1),
                'direction' => $dischargesToday >= $trailDis ? 'up' : 'down', 'good_up' => true,
            ],
            'deathsMonth' => [
                'value' => $deathsMonth, 'prior' => $deathsPrior, 'delta' => $deathsMonth - $deathsPrior,
                'direction' => $deathsMonth > $deathsPrior ? 'up' : ($deathsMonth < $deathsPrior ? 'down' : 'flat'),
                'good_up' => false,
            ],
            'occupancy' => [
                'value' => $occupancy, 'prior7mean' => $priorWeekOccupancy,
                'delta' => round($occupancy - $priorWeekOccupancy, 1),
                'direction' => $occupancy > $priorWeekOccupancy ? 'up' : 'down', 'good_up' => null,
            ],
        ];

        // ── Threshold alert strip (Phase 1, Item 4) ─────────────────────────────────────────────
        // admin-tunable rules → a dismissible severity-coloured banner. Live tier (cheap COUNTs that
        // reflect the last action). $deathsPrior (Item 2) and $boardingCount (Item 1) are reused.
        $alerts = [];
        if ($occupancy >= $settings->alert_overcensus_pct) {
            $alerts[] = ['key' => 'overcensus', 'severity' => 'danger',
                'message' => "Over-census: {$occupancy}% occupancy ({$activeWard} ward patients, {$wardBeds} beds).",
                'link' => '/patients'];
        }
        if ($boardingCount > $settings->alert_boarding_max) {
            $alerts[] = ['key' => 'boarding', 'severity' => 'warning',
                'message' => "{$boardingCount} patients medically cleared but still boarding (threshold: {$settings->alert_boarding_max}).",
                'link' => '/patients?view=boarding'];
        }
        if ($deathsPrior > 0) {
            $deathsDeltaPct = (int) round(($deathsMonth - $deathsPrior) / $deathsPrior * 100);
            if ($deathsDeltaPct >= $settings->alert_deaths_delta_pct) {
                $alerts[] = ['key' => 'deaths_delta', 'severity' => 'danger',
                    'message' => "Mortality this month ({$deathsMonth}) is {$deathsDeltaPct}% higher than last month ({$deathsPrior}).",
                    'link' => null];
            }
        } elseif ($deathsMonth > 0 && $deathsPrior === 0) {
            $alerts[] = ['key' => 'deaths_delta', 'severity' => 'warning',
                'message' => "{$deathsMonth} death(s) this month vs 0 last month.", 'link' => null];
        }
        if ($ytd['admissions'] > 0) {
            $readmitWindow = max(0, (int) ($settings->readmission_window_days ?? 3));
            $readmitYtd = (int) DB::table('admissions as a')
                ->join('admissions as prev', Admission::readmissionJoin($readmitWindow))
                ->whereBetween('a.admit_date', [$yearStart, $today])
                ->whereRaw('(a.current_location <> "ICU" OR a.current_location IS NULL)')
                ->whereNull('a.deleted_at')->whereNull('prev.deleted_at')   // Phase 4 — Item 1
                ->count();
            $readmitRate = round($readmitYtd / $ytd['admissions'] * 100, 1);
            if ($readmitRate >= $settings->alert_readmit_rate_pct) {
                $alerts[] = ['key' => 'readmits', 'severity' => 'warning',
                    'message' => "Readmission rate YTD: {$readmitRate}% ({$readmitYtd} of {$ytd['admissions']} non-ICU admissions).",
                    'link' => '/registry'];
            }
        }

        // ── 'My unit today' consultant lens (Phase 1, Item 5) ───────────────────────────────────
        // null for every non-consultant role (the Vue strip then renders nothing without a role check).
        // Boarding + sign-pending reuse the exact predicates from Item 1 and PatientsController — no drift.
        $myUnit = null;
        $viewer = auth()->user();
        if ($viewer && (int) $viewer->role === User::ROLE_CONSULTANT) {
            $myId = $viewer->id;
            $myActive = fn () => DB::table('admissions')->whereNull('discharge_date')->where('consultant_id', $myId)->whereNull('deleted_at');
            $myUnit = [
                'total' => (int) $myActive()->count(),
                'ward' => (int) $myActive()->whereRaw($nonIcu)->count(),
                'icu' => (int) $myActive()->where('current_location', 'ICU')->count(),
                'boarding' => (int) $myActive()->whereNotNull('medical_discharge_date')->count(),
                'new' => (int) $myActive()->where('is_new_assignment', 1)->count(),
                'signPending' => (int) DB::table('handover_signatures as hs')
                    ->where('hs.to_consultant_id', $myId)->whereNull('hs.signed_at')->whereNull('hs.voided_at')->count(),
                'myConsults' => (int) DB::table('consultations')
                    ->where('consultant_id', $myId)->whereNull('signoff_date')->whereNull('deleted_at')->count(),
                // HC-T8: personal "needs handover" count — active admissions of mine with no
                // handover note saved today. Live tier (uncached), same as the rest of myUnit.
                'handoverDue' => (int) Admission::needsHandoverToday()->where('consultant_id', $myId)->count(),
            ];
        }

        // ── Admin landing band (Wave 1 usability, Item 7) ───────────────────────────────────────
        // A compact operational-health row shown ONLY to admins on the Dashboard, deep-linking into
        // the regrouped admin sections. Reuses the SAME count sources as the existing surfaces (the
        // data-quality digest, the Security panel, Recently Deleted, the handover queue) so the band
        // can never drift from the pages it links to. null for non-admins → the Vue band renders
        // nothing (additive; no behavior change for clinical roles).
        $adminBand = null;
        if ($viewer && $viewer->isAdmin()) {
            // dqIssues == the digest total: the 5 data-quality canaries summed (DataQualityController
            // ::checks() is the single source the daily digest also consumes).
            $dq = app(DataQualityController::class)->checks();
            $dqIssues = array_sum(array_map(fn ($c) => $c->count(), $dq));

            // securityAnomalies mirrors the three Security-panel sections: 24h failed-login clusters,
            // first-seen IPs, and (while enforcement is on) MFA-noncompliant active users.
            $failedClusters = (int) DB::table('audit_log')->where('action', 'login.failed')
                ->where('created_at', '>=', DB::raw('NOW() - INTERVAL 24 HOUR'))
                ->distinct()->count(DB::raw('CONCAT(COALESCE(actor_name, ""), "|", COALESCE(ip, ""))'));
            $mfaLevel = (int) $settings->mfa_enforcement;
            $mfaNonCompliant = $mfaLevel > 0
                ? (int) User::where('active', 1)->whereNull('mfa_enrolled_at')
                    ->when($mfaLevel === 1, fn ($q) => $q->where('role', User::ROLE_ADMIN))->count()
                : 0;
            $securityAnomalies = $failedClusters + $mfaNonCompliant;

            $recentlyDeleted = (int) Admission::onlyTrashed()->count()
                + (int) \App\Models\Consultation::onlyTrashed()->count()
                + (int) User::onlyTrashed()->count();

            $pendingHandovers = (int) \App\Models\HandoverSignature::pending()->count();
            // HC-T8: unit-wide "needs handover" count (all consultants) — live tier, uncached,
            // matching pendingHandovers above (a different, narrower signature-based signal).
            $handoverDueUnit = (int) Admission::needsHandoverToday()->count();

            $adminBand = [
                'dqIssues' => (int) $dqIssues,
                'securityAnomalies' => $securityAnomalies,
                'recentlyDeleted' => $recentlyDeleted,
                'pendingHandovers' => $pendingHandovers,
                'handoverDueUnit' => $handoverDueUnit,
            ];
        }

        return Inertia::render('Dashboard', [
            'adminBand' => $adminBand,
            'kpis' => [
                'census' => $active, 'ward' => $activeWard, 'icu' => $activeIcu,
                'admissionsToday' => $admissionsToday, 'dischargesToday' => $dischargesToday,
                'activeConsults' => $activeConsults, 'deathsMonth' => $deathsMonth, 'avgLosMonth' => $avgLosMonth,
                'occupancy' => $occupancy, 'occupancyGauge' => $occupancyGauge, 'wardBeds' => $wardBeds, 'icuBeds' => $icuBeds,
            ],
            'boardingCount' => $boardingCount,
            'boardingWorklist' => $boardingWorklist,
            'deltas' => $deltas,
            'alerts' => $alerts,
            'myUnit' => $myUnit,
            'loadBands' => [
                'minHosp' => (int) $settings->min_hospitalist, 'maxHosp' => (int) $settings->max_hospitalist,
                'minSubs' => (int) $settings->min_subs, 'maxSubs' => (int) $settings->max_subs,
            ],
            'trend' => $trend,
            'consults' => $cons,
            'consultDonut' => ['signed24h' => $signed24h, 'active' => $activeConsults],
            'los' => ['labels' => array_keys($losBuckets), 'data' => array_values($losBuckets)],
            'mix' => ['hospitalist' => (int) ($mix['hosp'] ?? 0), 'subspecialty' => (int) ($mix['subs'] ?? 0), 'longterm' => (int) ($mix['longterm'] ?? 0)],
            'donutTotal' => $donutTotal,
            'donutTb' => $donutTb,
            'perConsultant' => $perConsultant,
            'consultantBoard' => $consultantBoard,
            'activity24h' => $activity24h,
            'ytd' => $ytd,
            'topDxWeek' => $topDxWeek,
            'topDxWeekNum' => $topDxWeekNum,
            'recent' => $recent,
            'generatedAt' => now()->format('D, d M Y · H:i'),
        ]);
    }
}
