<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\Patient;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * VALUE correctness of the statistics KPIs and dashboard occupancy against a hand-computed
 * synthetic fixture. This is the CI proxy for the manual legacy reconciliation and the safety
 * gate for any refactor of the metric queries (Wave D): if a formula drifts, these fail.
 *
 * Fixture (range queried: 2024-06-01..2024-06-30; readmission window = default 3 days):
 *   P5-prev  Ward admit 2024-05-28, disch 06-05, 'other transfer'   -> discharge+LOS(8); NOT a readmit-anchor
 *   A1       Ward admit 06-02,      disch 06-07, Alive, from ward   -> admission, discharge, LOS 5
 *   A2       Ward admit 06-03,      disch 06-06, Dead,  from ward   -> admission, discharge, death, LOS 3
 *   A3       ICU  admit 06-04,      disch 06-10, Alive, from ICU    -> icuAdmission only (non-ICU KPIs exclude)
 *   A4       Ward admit 06-09 (same patient as A1, 2d after its discharge), active -> admission + READMISSION
 *   A5       ICU  admit 06-05 (same patient as P5-prev, 0d gap)              -> icuAdmission, NOT a readmission
 *
 * Expected: admissions 3 · discharges 3 · deaths 1 · mortality 33.3 · icuAdmissions 2
 *           avgLos (5+3+8)/3 = 5.3 · readmissions 1 (transfer-prev excluded).
 */
class StatisticsValueTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'username' => 'sv_' . substr(md5(uniqid('', true)), 0, 8),
            'name' => 'SV Admin', 'password' => 'secret12345', 'role' => User::ROLE_ADMIN, 'active' => 1,
        ]);
    }

    private function seedFixture(): void
    {
        $p1 = Patient::create(['mrn' => '70000001', 'name' => 'P One']);
        $p2 = Patient::create(['mrn' => '70000002', 'name' => 'P Two']);
        $p3 = Patient::create(['mrn' => '70000003', 'name' => 'P Three']);
        $p5 = Patient::create(['mrn' => '70000005', 'name' => 'P Five']);

        $base = ['is_longterm' => 0, 'is_new_assignment' => 0];
        // P5-prev: ward episode that ENDED IN A TRANSFER (counts as discharge per legacy, NOT a readmit anchor)
        Admission::create([...$base, 'patient_id' => $p5->id, 'admit_date' => '2024-05-28', 'discharge_date' => '2024-06-05',
            'current_location' => 'Ward', 'transfer_type' => 'other transfer']);
        // A1
        Admission::create([...$base, 'patient_id' => $p1->id, 'admit_date' => '2024-06-02', 'discharge_date' => '2024-06-07',
            'current_location' => 'Ward', 'outcome' => 'Alive', 'transfer_type' => 'discharge from ward']);
        // A2 (death)
        Admission::create([...$base, 'patient_id' => $p2->id, 'admit_date' => '2024-06-03', 'discharge_date' => '2024-06-06',
            'current_location' => 'Ward', 'outcome' => 'Dead', 'transfer_type' => 'discharge from ward']);
        // A3 (ICU episode)
        Admission::create([...$base, 'patient_id' => $p3->id, 'admit_date' => '2024-06-04', 'discharge_date' => '2024-06-10',
            'current_location' => 'ICU', 'outcome' => 'Alive', 'transfer_type' => 'discharge from ICU']);
        // A4: genuine 72h readmission of P1 (gap 2d after a REAL ward discharge), still active
        Admission::create([...$base, 'patient_id' => $p1->id, 'admit_date' => '2024-06-09', 'current_location' => 'Ward']);
        // A5: P5 re-enters via transfer continuation (prev = 'other transfer') -> NOT a readmission
        Admission::create([...$base, 'patient_id' => $p5->id, 'admit_date' => '2024-06-05', 'current_location' => 'ICU']);
    }

    public function test_statistics_kpi_values_match_hand_computed_fixture(): void
    {
        $this->seedFixture();

        $this->actingAs($this->admin())
            ->get('/statistics?from=2024-06-01&to=2024-06-30')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('kpis.admissions', 3)        // A1, A2, A4 (non-ICU; A3/A5 are ICU; P5-prev outside range)
                ->where('kpis.discharges', 3)        // A1, A2, P5-prev (transfer-out counts as discharge per legacy)
                ->where('kpis.deaths', 1)            // A2 (all locations)
                ->where('kpis.mortalityRate', 33.3)  // 1/3 (documented mixed-denominator definition)
                ->where('kpis.icuAdmissions', 2)     // A3, A5
                ->where('kpis.avgLos', 5.3)          // (5+3+8)/3
                ->where('kpis.readmissions', 1)      // A4 only; A5 excluded (prev was a transfer)
            );
    }

    public function test_readmission_respects_transfer_exclusion_when_window_widens(): void
    {
        $this->seedFixture();
        Setting::current()->update(['readmission_window_days' => 10]);

        // a wider window must still not turn the transfer continuation (A5) into a readmission
        $this->actingAs($this->admin())
            ->get('/statistics?from=2024-06-01&to=2024-06-30')
            ->assertInertia(fn (AssertableInertia $p) => $p->where('kpis.readmissions', 1));
    }

    public function test_null_typed_prior_discharge_counts_as_readmission_anchor(): void
    {
        // J1-4 (deliberate definition change, legacy parity): a prior episode closed WITHOUT a
        // transfer_type (NULL — historical rows) is a valid readmission anchor; explicit
        // transfer types stay excluded (A5 above). The recon-pinned count may legitimately rise.
        $this->seedFixture();
        $p6 = Patient::create(['mrn' => '70000006', 'name' => 'P Six']);
        Admission::create(['is_longterm' => 0, 'is_new_assignment' => 0, 'patient_id' => $p6->id,
            'admit_date' => '2024-06-01', 'discharge_date' => '2024-06-10',
            'current_location' => 'Ward', 'outcome' => 'Alive', 'transfer_type' => null]);
        Admission::create(['is_longterm' => 0, 'is_new_assignment' => 0, 'patient_id' => $p6->id,
            'admit_date' => '2024-06-12', 'current_location' => 'Ward']);   // 2d gap -> readmission

        $this->actingAs($this->admin())
            ->get('/statistics?from=2024-06-01&to=2024-06-30')
            ->assertInertia(fn (AssertableInertia $p) => $p->where('kpis.readmissions', 2));
    }

    public function test_dashboard_occupancy_is_active_ward_over_ward_beds(): void
    {
        $this->seedFixture(); // active: A4 (Ward) + A5 (ICU)
        Setting::current()->update(['ward_beds' => 10, 'icu_beds' => 5]);

        $this->actingAs($this->admin())->get('/')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('kpis.census', 2)
            ->where('kpis.ward', 1)
            ->where('kpis.icu', 1)
            ->where('kpis.occupancy', fn ($v) => (float) $v === 10.0)   // 1 active ward / 10 beds (JSON may drop the .0)
            ->where('kpis.wardBeds', 10)
        );
    }
}
