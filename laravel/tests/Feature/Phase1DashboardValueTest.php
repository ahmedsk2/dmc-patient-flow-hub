<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\Consultation;
use App\Models\HandoverSignature;
use App\Models\Patient;
use App\Models\Setting;
use App\Models\User;
use App\Support\DashboardCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Phase 1 VALUE tests — every new derived dashboard metric (boarding count + worklist, KPI
 * period-over-period deltas, threshold-alert fire/no-fire boundaries, the consultant "my unit"
 * lens, load-fairness bands, and the drill-through board filters) is pinned against the seeded
 * DB and, where a defining formula exists, re-derived with INDEPENDENT SQL — the same value-test
 * discipline established in StatisticsValueTest. A formula drift breaks a test before production.
 */
class Phase1DashboardValueTest extends TestCase
{
    use RefreshDatabase;

    // ---- actors -------------------------------------------------------------------------------

    private function admin(): User
    {
        return User::create([
            'username' => 'p1_' . substr(md5(uniqid('', true)), 0, 10),
            'name' => 'P1 Admin', 'password' => 'secret12345', 'role' => User::ROLE_ADMIN, 'active' => 1,
        ]);
    }

    private function consultant(array $overrides = []): User
    {
        return User::create(array_merge([
            'username' => 'p1c_' . substr(md5(uniqid('', true)), 0, 10),
            'name' => 'P1 Consultant', 'full_name' => 'Dr P1', 'password' => 'secret12345',
            'role' => User::ROLE_CONSULTANT, 'active' => 1, 'on_service' => 1, 'specialty_id' => 1,
        ], $overrides));
    }

    private function patient(): Patient
    {
        return Patient::create(['mrn' => (string) random_int(10000000, 99999999), 'name' => 'P1 Patient']);
    }

    /** An admission with sane phase-1 defaults; pass overrides for the scenario under test. */
    private function admission(array $overrides = []): Admission
    {
        return Admission::create(array_merge([
            'patient_id' => $this->patient()->id,
            'admit_date' => Carbon::today()->toDateString(),
            'current_location' => 'Ward',
            'is_longterm' => 0, 'is_new_assignment' => 0,
        ], $overrides));
    }

    // =====================================================================================
    // Item 1 — Boarding worklist
    // =====================================================================================

    public function test_boarding_count_matches_independent_sql(): void
    {
        // one fully active, one boarding (medically cleared, bed occupied), one fully discharged.
        // admit_date precedes the medical-discharge dates (Phase 4 — Item 7 CHECK: med_disch >= admit).
        $early = Carbon::today()->subDays(20)->toDateString();
        $this->admission();   // active, non-boarding
        $this->admission(['admit_date' => $early, 'medical_discharge_date' => Carbon::today()->subDays(5)->toDateString()]); // boarding
        $this->admission(['admit_date' => $early, 'medical_discharge_date' => Carbon::today()->subDays(2)->toDateString(),
            'discharge_date' => Carbon::today()->toDateString(), 'outcome' => 'Alive']); // discharged

        // independent SQL — the defining boarding predicate
        $independent = (int) DB::table('admissions')
            ->whereNull('discharge_date')->whereNotNull('medical_discharge_date')->count();
        $this->assertSame(1, $independent);

        $this->actingAs($this->admin())->get('/')->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->component('Dashboard', false)
                ->where('boardingCount', 1));
    }

    public function test_boarding_worklist_ranks_by_delay_desc(): void
    {
        $early = Carbon::today()->subDays(20)->toDateString();
        $this->admission(['admit_date' => $early, 'medical_discharge_date' => Carbon::today()->subDays(2)->toDateString()]);
        $this->admission(['admit_date' => $early, 'medical_discharge_date' => Carbon::today()->subDays(7)->toDateString()]);

        $this->actingAs($this->admin())->get('/')->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('boardingWorklist.0.delay_days', 7)
                ->where('boardingWorklist.1.delay_days', 2)
                ->where('boardingCount', 2));
    }

    public function test_boarding_count_zero_when_none(): void
    {
        $this->admission();                                                       // active, not boarding
        $this->admission(['discharge_date' => Carbon::today()->toDateString(),
            'medical_discharge_date' => Carbon::today()->toDateString(), 'outcome' => 'Alive']); // discharged

        $this->actingAs($this->admin())->get('/')->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('boardingCount', 0)
                ->where('boardingWorklist', []));
    }

    public function test_boarding_board_filter_returns_only_medically_discharged(): void
    {
        $c = $this->consultant();
        $this->admission(['consultant_id' => $c->id]);                                              // not boarding
        // admit precedes the medical discharge (Phase 4 — Item 7 CHECK)
        $this->admission(['consultant_id' => $c->id, 'admit_date' => Carbon::today()->subDays(10)->toDateString(),
            'medical_discharge_date' => Carbon::today()->subDay()->toDateString()]); // boarding

        $this->actingAs($this->admin())->get('/patients?view=boarding')->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->component('Patients/Index', false)
                ->where('groups', function ($groups) {
                    foreach ($groups as $g) {
                        foreach ($g['patients'] as $pt) {
                            if ($pt['medically_discharged'] !== true) {
                                return false;
                            }
                        }
                    }
                    // and exactly one boarding patient overall
                    $total = collect($groups)->sum(fn ($g) => count($g['patients']));

                    return $total === 1;
                }));
    }

    // =====================================================================================
    // Item 2 — KPI period-over-period deltas
    // =====================================================================================

    public function test_deltas_admissions_uses_7day_mean_from_admBy_map(): void
    {
        // 2 non-ICU admissions on each of the 7 prior days, 4 today
        foreach (range(1, 7) as $i) {
            $d = Carbon::today()->subDays($i)->toDateString();
            $this->admission(['admit_date' => $d]);
            $this->admission(['admit_date' => $d]);
        }
        $this->admission(['admit_date' => Carbon::today()->toDateString()]);
        $this->admission(['admit_date' => Carbon::today()->toDateString()]);
        $this->admission(['admit_date' => Carbon::today()->toDateString()]);
        $this->admission(['admit_date' => Carbon::today()->toDateString()]);

        $this->actingAs($this->admin())->get('/')->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('deltas.admissions.value', 4)
                ->where('deltas.admissions.mean7', fn ($v) => (float) $v === 2.0)
                ->where('deltas.admissions.delta', fn ($v) => (float) $v === 2.0)
                ->where('deltas.admissions.direction', 'up'));
    }

    public function test_deltas_deaths_month_compares_to_prior_calendar_month(): void
    {
        $priorMonth = Carbon::today()->subMonthNoOverflow()->startOfMonth();
        // 1 death this month, 3 deaths prior month (all non-ICU complete discharges)
        $this->admission(['admit_date' => Carbon::today()->startOfMonth()->toDateString(),
            'discharge_date' => Carbon::today()->toDateString(), 'outcome' => 'Dead', 'transfer_type' => 'discharge from ward']);
        foreach (range(1, 3) as $i) {
            $this->admission(['admit_date' => $priorMonth->toDateString(),
                'discharge_date' => $priorMonth->toDateString(), 'outcome' => 'Dead', 'transfer_type' => 'discharge from ward']);
        }

        $this->actingAs($this->admin())->get('/')->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('deltas.deathsMonth.value', 1)
                ->where('deltas.deathsMonth.prior', 3)
                ->where('deltas.deathsMonth.delta', -2)
                ->where('deltas.deathsMonth.direction', 'down'));
    }

    public function test_deltas_flat_when_equal(): void
    {
        // 2 admissions/day for the 7 prior days (mean = 2), and exactly 2 today => delta 0
        foreach (range(1, 7) as $i) {
            $d = Carbon::today()->subDays($i)->toDateString();
            $this->admission(['admit_date' => $d]);
            $this->admission(['admit_date' => $d]);
        }
        $this->admission(['admit_date' => Carbon::today()->toDateString()]);
        $this->admission(['admit_date' => Carbon::today()->toDateString()]);

        $this->actingAs($this->admin())->get('/')->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('deltas.admissions.delta', fn ($v) => (float) $v === 0.0));
    }

    // =====================================================================================
    // Item 4 — Threshold alert strip
    // =====================================================================================

    private function alertKeys(AssertableInertia $p): array
    {
        return collect($p->toArray()['props']['alerts'] ?? [])->pluck('key')->all();
    }

    public function test_alert_fires_when_occupancy_at_threshold(): void
    {
        Setting::current()->update(['ward_beds' => 10, 'alert_overcensus_pct' => 100]);
        $c = $this->consultant();
        foreach (range(1, 10) as $i) {
            $this->admission(['consultant_id' => $c->id, 'current_location' => 'Ward']); // 10 active ward = 100%
        }

        $this->actingAs($this->admin())->get('/')->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('alerts', fn ($alerts) => collect($alerts)->contains(fn ($a) => $a['key'] === 'overcensus')));
    }

    public function test_alert_does_not_fire_below_threshold(): void
    {
        Setting::current()->update(['ward_beds' => 10, 'alert_overcensus_pct' => 100]);
        $c = $this->consultant();
        foreach (range(1, 9) as $i) {
            $this->admission(['consultant_id' => $c->id, 'current_location' => 'Ward']); // 9 active ward = 90%
        }

        $this->actingAs($this->admin())->get('/')->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('alerts', fn ($alerts) => ! collect($alerts)->contains(fn ($a) => $a['key'] === 'overcensus')));
    }

    public function test_alert_boarding_fires_above_max(): void
    {
        Setting::current()->update(['alert_boarding_max' => 3, 'ward_beds' => 100, 'alert_overcensus_pct' => 200]);
        $c = $this->consultant();
        foreach (range(1, 4) as $i) {
            $this->admission(['consultant_id' => $c->id, 'admit_date' => Carbon::today()->subDays(10)->toDateString(),
                'medical_discharge_date' => Carbon::today()->subDay()->toDateString()]);
        }

        $this->actingAs($this->admin())->get('/')->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('alerts', fn ($alerts) => collect($alerts)->contains(fn ($a) => $a['key'] === 'boarding')));
    }

    public function test_alert_deaths_delta_fires_on_sharp_rise(): void
    {
        Setting::current()->update(['alert_deaths_delta_pct' => 50, 'ward_beds' => 100, 'alert_overcensus_pct' => 200]);
        $priorMonth = Carbon::today()->subMonthNoOverflow()->startOfMonth();
        $early = $priorMonth->copy()->subMonth()->toDateString();   // admit precedes discharge (Phase 4 — Item 7 CHECK)
        // 2 prior, 4 this month = 100% rise (>= 50%)
        foreach (range(1, 2) as $i) {
            $this->admission(['admit_date' => $early, 'discharge_date' => $priorMonth->toDateString(), 'outcome' => 'Dead', 'transfer_type' => 'discharge from ward']);
        }
        foreach (range(1, 4) as $i) {
            $this->admission(['admit_date' => $early, 'discharge_date' => Carbon::today()->toDateString(), 'outcome' => 'Dead', 'transfer_type' => 'discharge from ward']);
        }

        $this->actingAs($this->admin())->get('/')->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('alerts', fn ($alerts) => collect($alerts)->contains(fn ($a) => $a['key'] === 'deaths_delta')));
    }

    public function test_alert_deaths_delta_no_fire_within_threshold(): void
    {
        Setting::current()->update(['alert_deaths_delta_pct' => 50, 'ward_beds' => 100, 'alert_overcensus_pct' => 200]);
        $priorMonth = Carbon::today()->subMonthNoOverflow()->startOfMonth();
        $early = $priorMonth->copy()->subMonth()->toDateString();   // admit precedes discharge (Phase 4 — Item 7 CHECK)
        // 3 prior, 4 this month = 33% rise (< 50%)
        foreach (range(1, 3) as $i) {
            $this->admission(['admit_date' => $early, 'discharge_date' => $priorMonth->toDateString(), 'outcome' => 'Dead', 'transfer_type' => 'discharge from ward']);
        }
        foreach (range(1, 4) as $i) {
            $this->admission(['admit_date' => $early, 'discharge_date' => Carbon::today()->toDateString(), 'outcome' => 'Dead', 'transfer_type' => 'discharge from ward']);
        }

        $this->actingAs($this->admin())->get('/')->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('alerts', fn ($alerts) => ! collect($alerts)->contains(fn ($a) => $a['key'] === 'deaths_delta')));
    }

    public function test_alert_readmit_rate_fires(): void
    {
        Setting::current()->update([
            'alert_readmit_rate_pct' => 10, 'readmission_window_days' => 3,
            'ward_beds' => 100, 'alert_overcensus_pct' => 200,
        ]);
        $window = 3;
        $yearStart = Carbon::today()->startOfYear()->toDateString();
        // a clean prior discharge this year, then a readmission 2 days later — within the window
        $p = $this->patient();
        Admission::create(['patient_id' => $p->id, 'is_longterm' => 0, 'is_new_assignment' => 0,
            'admit_date' => Carbon::today()->subDays(10)->toDateString(),
            'discharge_date' => Carbon::today()->subDays(5)->toDateString(),
            'current_location' => 'Ward', 'outcome' => 'Alive', 'transfer_type' => 'discharge from ward']);
        Admission::create(['patient_id' => $p->id, 'is_longterm' => 0, 'is_new_assignment' => 0,
            'admit_date' => Carbon::today()->subDays(3)->toDateString(), 'current_location' => 'Ward']);
        // pad with non-readmit admissions so the rate stays well above 10%
        $this->admission(['admit_date' => Carbon::today()->subDay()->toDateString()]);

        // independent count using the SAME readmissionJoin the controller uses (column qualified to
        // 'a.' because NON_ICU_SQL's bare current_location is ambiguous across the self-join)
        $independent = (int) DB::table('admissions as a')
            ->join('admissions as prev', Admission::readmissionJoin($window))
            ->whereBetween('a.admit_date', [$yearStart, Carbon::today()->toDateString()])
            ->whereRaw('(a.current_location <> "ICU" OR a.current_location IS NULL)')
            ->count();
        $this->assertGreaterThanOrEqual(1, $independent);

        $this->actingAs($this->admin())->get('/')->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('alerts', fn ($alerts) => collect($alerts)->contains(fn ($a) => $a['key'] === 'readmits')));
    }

    public function test_alerts_empty_when_all_within_bounds(): void
    {
        // healthy fixture: 2 ward patients, 100 beds, generous thresholds, no boarding/deaths/readmits
        Setting::current()->update([
            'ward_beds' => 100, 'alert_overcensus_pct' => 100, 'alert_boarding_max' => 5,
            'alert_readmit_rate_pct' => 90, 'alert_deaths_delta_pct' => 500, 'readmission_window_days' => 3,
        ]);
        $c = $this->consultant();
        $this->admission(['consultant_id' => $c->id]);
        $this->admission(['consultant_id' => $c->id]);

        $this->actingAs($this->admin())->get('/')->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('alerts', []));
    }

    // =====================================================================================
    // Item 5 — 'My unit today' consultant lens
    // =====================================================================================

    public function test_my_unit_null_for_non_consultant(): void
    {
        $this->actingAs($this->admin())->get('/')->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('myUnit', null));
    }

    public function test_my_unit_populated_for_consultant(): void
    {
        $c = $this->consultant();
        // 3 active: 1 ward+boarding, 1 ICU, 1 new-since-yesterday (assigned now). total=3.
        $this->admission(['consultant_id' => $c->id, 'current_location' => 'Ward',
            'admit_date' => Carbon::today()->subDays(10)->toDateString(),   // admit precedes med discharge (Phase 4 — Item 7 CHECK)
            'medical_discharge_date' => Carbon::today()->subDay()->toDateString()]);             // ward + boarding
        $this->admission(['consultant_id' => $c->id, 'current_location' => 'ICU']);              // ICU
        $this->admission(['consultant_id' => $c->id, 'current_location' => 'Ward', 'assigned_at' => now()]); // new

        $this->actingAs($c)->get('/')->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('myUnit.total', 3)
                ->where('myUnit.ward', 2)        // boarding ward + new ward
                ->where('myUnit.icu', 1)
                ->where('myUnit.boarding', 1)
                ->where('myUnit.new', 1));
    }

    public function test_my_unit_sign_pending_counts_only_this_consultant(): void
    {
        $a = $this->consultant();
        $b = $this->consultant();
        // 2 pending signatures for A, 1 for B
        foreach (range(1, 2) as $i) {
            $adm = $this->admission(['consultant_id' => $a->id]);
            HandoverSignature::create(['admission_id' => $adm->id, 'to_consultant_id' => $a->id, 'required_at' => now()]);
        }
        $admB = $this->admission(['consultant_id' => $b->id]);
        HandoverSignature::create(['admission_id' => $admB->id, 'to_consultant_id' => $b->id, 'required_at' => now()]);

        $this->actingAs($a)->get('/')->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('myUnit.signPending', 2));
    }

    public function test_my_unit_consults_awaiting_signoff(): void
    {
        $a = $this->consultant();
        $b = $this->consultant();
        foreach (range(1, 2) as $i) {
            Consultation::create(['patient_name' => 'X', 'mrn' => (string) random_int(10000000, 99999999),
                'consultation_date' => Carbon::today()->toDateString(), 'consultant_id' => $a->id]);
        }
        Consultation::create(['patient_name' => 'Y', 'mrn' => (string) random_int(10000000, 99999999),
            'consultation_date' => Carbon::today()->toDateString(), 'consultant_id' => $b->id]);

        $this->actingAs($a)->get('/')->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('myUnit.myConsults', 2));
    }

    // =====================================================================================
    // Item 6 — Consultant load-fairness
    // =====================================================================================

    public function test_load_bands_passed_to_dashboard(): void
    {
        $s = Setting::current();
        $this->actingAs($this->admin())->get('/')->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('loadBands.minHosp', (int) $s->min_hospitalist)
                ->where('loadBands.maxHosp', (int) $s->max_hospitalist)
                ->where('loadBands.minSubs', (int) $s->min_subs)
                ->where('loadBands.maxSubs', (int) $s->max_subs));
    }

    public function test_perConsultant_includes_specialty_id(): void
    {
        $c = $this->consultant(['specialty_id' => 2]);
        $this->admission(['consultant_id' => $c->id]);

        $this->actingAs($this->admin())->get('/')->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('perConsultant', fn ($rows) =>
                    collect($rows)->every(fn ($r) => is_int($r['specialty_id']))
                    && collect($rows)->contains(fn ($r) => $r['specialty_id'] === 2)));
    }

    public function test_perConsultant_includes_id(): void
    {
        $c = $this->consultant();
        $this->admission(['consultant_id' => $c->id]);

        $this->actingAs($this->admin())->get('/')->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('perConsultant', fn ($rows) =>
                    collect($rows)->every(fn ($r) => is_int($r['id']) && $r['id'] > 0)
                    && collect($rows)->contains(fn ($r) => $r['id'] === $c->id)));
    }

    // =====================================================================================
    // Item 3 — Drill-through board filters
    // =====================================================================================

    public function test_patients_board_accepts_consultant_id_filter(): void
    {
        $c1 = $this->consultant();
        $c2 = $this->consultant();
        $this->admission(['consultant_id' => $c1->id]);
        $this->admission(['consultant_id' => $c1->id]);
        $this->admission(['consultant_id' => $c2->id]);
        $this->admission(['consultant_id' => $c2->id]);

        $this->actingAs($this->admin())->get("/patients?consultant_id={$c1->id}")->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('groups', function ($groups) use ($c1) {
                    // only c1's group has patients; total = 2
                    $total = 0;
                    foreach ($groups as $g) {
                        if (count($g['patients']) > 0 && $g['id'] !== $c1->id) {
                            return false;
                        }
                        $total += count($g['patients']);
                    }

                    return $total === 2;
                }));
    }

    public function test_patients_board_consultant_id_filter_respects_d1_scope(): void
    {
        $a = $this->consultant();
        $b = $this->consultant();
        $this->admission(['consultant_id' => $a->id]);
        $this->admission(['consultant_id' => $b->id]);

        // Consultant A drills to B's id — D1 scope must win: A sees only their own patients (none of B's)
        $this->actingAs($a)->get("/patients?consultant_id={$b->id}")->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('groups', function ($groups) use ($b) {
                    foreach ($groups as $g) {
                        if ($g['id'] === $b->id && count($g['patients']) > 0) {
                            return false;   // must NOT see B's patients
                        }
                    }

                    return true;
                }));
    }

    public function test_patients_board_specialty_id_filter(): void
    {
        $hosp = $this->consultant(['specialty_id' => 1]);
        $subs = $this->consultant(['specialty_id' => 2]);
        $this->admission(['consultant_id' => $hosp->id]);
        $this->admission(['consultant_id' => $subs->id]);

        $this->actingAs($this->admin())->get('/patients?specialty_id=2')->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('groups', function ($groups) use ($subs) {
                    // only the subspecialist's group carries patients
                    foreach ($groups as $g) {
                        if (count($g['patients']) > 0 && $g['id'] !== $subs->id) {
                            return false;
                        }
                    }
                    $total = collect($groups)->sum(fn ($g) => count($g['patients']));

                    return $total === 1;
                }));
    }

    // =====================================================================================
    // Item 7 — Cache freshness tiers
    // =====================================================================================

    public function test_cache_is_busted_after_medical_discharge(): void
    {
        $c = $this->consultant();
        $admin = $this->admin();
        $adm = $this->admission(['consultant_id' => $c->id]);

        // prime
        $this->actingAs($admin)->get('/')->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('boardingCount', 0));

        // medically discharge it (mutation must bust cache; boardingCount is live anyway, but the
        // heavy consultantBoard "active" count derives from medical_discharge_date too)
        $this->actingAs($admin)->post("/admissions/{$adm->id}/medical-discharge", [
            'medical_discharge_date' => Carbon::today()->toDateString(),
            'delay_reason' => 'Physical',
        ])->assertRedirect();

        $this->actingAs($admin)->get('/')->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('boardingCount', 1));
    }

    public function test_live_kpis_always_reflect_current_state(): void
    {
        $c = $this->consultant();
        $admin = $this->admin();
        $this->admission(['consultant_id' => $c->id]);

        $this->actingAs($admin)->get('/')->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('kpis.census', 1));

        // add an admission directly via DB (bypassing the cache-bust path) — live census must update
        $this->admission(['consultant_id' => $c->id]);

        $this->actingAs($admin)->get('/')->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('kpis.census', 2));
    }

    // =====================================================================================
    // Control — alert thresholds persist (Item 4 settings round-trip)
    // =====================================================================================

    public function test_control_saves_alert_thresholds(): void
    {
        $s = Setting::current();
        $payload = [
            'min_hospitalist' => $s->min_hospitalist, 'max_hospitalist' => $s->max_hospitalist,
            'min_subs' => $s->min_subs, 'max_subs' => $s->max_subs,
            'short_los' => $s->short_los, 'long_los' => $s->long_los,
            'ward_beds' => $s->ward_beds, 'icu_beds' => $s->icu_beds,
            'readmission_window_days' => $s->readmission_window_days ?? 3,
            'mfa_enforcement' => $s->mfa_enforcement ?? 0,
            'alert_overcensus_pct' => 95, 'alert_boarding_max' => 7,
            'alert_readmit_rate_pct' => 12, 'alert_deaths_delta_pct' => 40,
            // Phase 4 thresholds (now required by updateSettings)
            'idle_timeout_minutes' => $s->idle_timeout_minutes ?? 30, 'abs_timeout_minutes' => $s->abs_timeout_minutes ?? 0,
            'failed_login_notify_threshold' => $s->failed_login_notify_threshold ?? 5, 'dq_los_multiplier' => $s->dq_los_multiplier ?? 2,
        ];

        $this->actingAs($this->admin())->put('/control/settings', $payload)->assertRedirect();

        $fresh = Setting::query()->orderBy('id')->first();
        $this->assertSame(95, (int) $fresh->alert_overcensus_pct);
        $this->assertSame(7, (int) $fresh->alert_boarding_max);
        $this->assertSame(12, (int) $fresh->alert_readmit_rate_pct);
        $this->assertSame(40, (int) $fresh->alert_deaths_delta_pct);
    }

    // =====================================================================================
    // Item 7 — model-event busting covers writes OUTSIDE PatientActionController
    // =====================================================================================

    public function test_model_events_bust_heavy_cache_on_consultation_and_admission_write(): void
    {
        $admin = $this->admin();

        // prime the heavy tier
        $this->actingAs($admin)->get('/')->assertOk();
        $this->assertTrue(Cache::has(DashboardCache::KEY), 'heavy tier should be cached after a load');

        // a consultation write (ConsultationsController path — no explicit bust) must still bust it
        Consultation::create([
            'mrn' => '7000001', 'patient_name' => 'Cache Probe', 'age' => 50,
            'consultation_date' => Carbon::today()->toDateString(), 'consultation_from' => 'Ward',
            'to_service' => 'Cardiology', 'consultant_id' => $admin->id, 'indication' => [1],
            'current_location' => 'Ward',
        ]);
        $this->assertFalse(Cache::has(DashboardCache::KEY), 'consultation write must bust the heavy cache via model event');

        // re-prime, then an admission write must also bust it
        $this->actingAs($admin)->get('/')->assertOk();
        $this->assertTrue(Cache::has(DashboardCache::KEY));
        $this->admission();
        $this->assertFalse(Cache::has(DashboardCache::KEY), 'admission write must bust the heavy cache via model event');
    }

    // =====================================================================================
    // Item 7 — the cached heavy payload must be serialize-safe (plain arrays, no Collections)
    // =====================================================================================

    /**
     * Regression: a Collection cached in dashboard.heavy comes back as __PHP_Incomplete_Class on a
     * SERIALIZING cache driver (file/database, as on prod) → the cache-HIT dashboard load 500s when
     * the delta block subscripts $admBy[$date]. The test cache driver is `array` (in-memory, no
     * serialize round-trip), so it can't reproduce the incomplete-class itself — but the durable
     * guard is that every value the controller subscripts/iterates after a cache read is a PLAIN
     * ARRAY, never a Collection. (A plain array is the only thing guaranteed to survive any cache
     * driver's serialize→deserialize with zero class-loading.)
     */
    public function test_dashboard_heavy_cache_payload_survives_a_class_restricted_unserialize(): void
    {
        // a seeded row makes mix / perConsultant / consultantBoard non-empty
        $this->admission(['consultant_id' => $this->consultant()->id]);
        $this->actingAs($this->admin())->get('/')->assertOk();

        $heavy = Cache::get(DashboardCache::KEY);
        $this->assertIsArray($heavy);

        // Reproduce the prod failure mode regardless of the test cache driver: prod's cache
        // deserializes with NO classes allowed, so ANY object left in the payload (a Collection
        // from ->pluck/->map, or a stdClass from ->first()) returns as __PHP_Incomplete_Class and
        // 500s the dashboard on the cache-HIT path. A payload of only arrays + scalars round-trips
        // identically; assertEquals fails loudly the moment any cached value is an object.
        $restricted = unserialize(serialize($heavy), ['allowed_classes' => false]);
        $this->assertEquals($heavy, $restricted,
            'dashboard.heavy must contain ONLY arrays/scalars — an object becomes __PHP_Incomplete_Class on a class-restricted cache unserialize (as on prod) and breaks the dashboard');
    }
}
