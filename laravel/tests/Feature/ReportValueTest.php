<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\Patient;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Phase 3 — §3.8: cross-month-boundary VALUE tests for the annual booklet families (census,
 * discharges, deaths, ICU, weekend, avg ward LOS, readmissions). A formula drift here fails CI.
 *
 * Fixture (year 2024; "now" frozen at 2025-01-01 so every month is fully elapsed):
 *   P1  Ward admit 2024-01-29, disch 2024-02-04, Alive, 'discharge from ward' (LOS 6; spans Jan→Feb)
 *   P2  ICU  admit 2024-02-01, disch 2024-02-10, Dead,  'discharge from ICU'  (ICU; LOS 9)
 *   P3  Ward admit 2024-02-03, disch 2024-02-09, Alive, 'discharge from ward' (LOS 6; Fri 02-09 = weekend)
 *   P4  Ward admit 2024-01-15, ACTIVE (no discharge)                           (Jan census/admission)
 *
 * Hand-computed (range 2024-01-01..2024-12-31):
 *   Jan: admissions 2 (P1,P4), discharges 0 (P1 discharges in Feb), census 2, deaths 0, icu 0
 *   Feb: admissions 2 (P2,P3), NON-ICU discharges 2 (P1,P3 — P2 is ICU, excluded), deaths 1 (P2,
 *        all locations), icu 1 (P2), weekend 1 (P3 Fri 02-09)
 *   avg ward LOS over non-ICU discharges in the year: P1=6, P3=6 -> 6.0
 *   readmissions: 0 (no qualifying re-entry in the fixture)
 */
class ReportValueTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['username' => 'rv_admin_'.substr(md5(uniqid('', true)), 0, 6),
            'name' => 'RV Admin', 'password' => 'secret12345', 'role' => User::ROLE_ADMIN, 'active' => 1,
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now()]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2025-01-01'); // every 2024 month is fully elapsed + capped deterministically
        $this->seedFixture();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function seedFixture(): void
    {
        $p1 = Patient::create(['mrn' => '16000001', 'name' => 'P1']);
        $p2 = Patient::create(['mrn' => '16000002', 'name' => 'P2']);
        $p3 = Patient::create(['mrn' => '16000003', 'name' => 'P3']);
        $p4 = Patient::create(['mrn' => '16000004', 'name' => 'P4']);
        $base = ['is_longterm' => 0, 'is_new_assignment' => 0];

        Admission::create([...$base, 'patient_id' => $p1->id, 'admit_date' => '2024-01-29', 'discharge_date' => '2024-02-04',
            'current_location' => 'Ward', 'outcome' => 'Alive', 'transfer_type' => 'discharge from ward']);
        Admission::create([...$base, 'patient_id' => $p2->id, 'admit_date' => '2024-02-01', 'discharge_date' => '2024-02-10',
            'current_location' => 'ICU', 'outcome' => 'Dead', 'transfer_type' => 'discharge from ICU']);
        Admission::create([...$base, 'patient_id' => $p3->id, 'admit_date' => '2024-02-03', 'discharge_date' => '2024-02-09',
            'current_location' => 'Ward', 'outcome' => 'Alive', 'transfer_type' => 'discharge from ward']);
        Admission::create([...$base, 'patient_id' => $p4->id, 'admit_date' => '2024-01-15',
            'current_location' => 'Ward']); // active
    }

    private function months(callable $assert): void
    {
        $this->actingAs($this->admin())->get('/reports?year=2024')->assertInertia($assert);
    }

    public function test_report_jan_admissions_count(): void
    {
        $this->months(fn (AssertableInertia $p) => $p->where('months.0.admissions', 2));
    }

    public function test_report_jan_discharges_zero(): void
    {
        $this->months(fn (AssertableInertia $p) => $p->where('months.0.discharges', 0));
    }

    public function test_report_feb_discharges_count(): void
    {
        // months[].discharges is NON-ICU only: P1 + P3 = 2 (P2 is an ICU episode, excluded)
        $this->months(fn (AssertableInertia $p) => $p->where('months.1.discharges', 2));
    }

    public function test_report_feb_icu_count(): void
    {
        $this->months(fn (AssertableInertia $p) => $p->where('months.1.icu', 1));
    }

    public function test_report_feb_deaths_count(): void
    {
        $this->months(fn (AssertableInertia $p) => $p->where('months.1.deaths', 1));
    }

    public function test_report_feb_weekend_discharge(): void
    {
        // P3 discharged 2024-02-09 (Friday, DAYOFWEEK 6) — the only weekend non-ICU discharge
        $this->months(fn (AssertableInertia $p) => $p->where('months.1.weekend', 1));
    }

    public function test_report_jan_census_count(): void
    {
        // P1 (active in Jan, discharged Feb) + P4 (active) -> 2
        $this->months(fn (AssertableInertia $p) => $p->where('months.0.census', 2));
    }

    public function test_report_avg_ward_los(): void
    {
        // non-ICU discharges in the year: P1 (LOS 6), P3 (LOS 6) -> avg 6.0 (P2 is ICU, excluded)
        $this->months(fn (AssertableInertia $p) => $p->where('avgLos', fn ($v) => (float) $v === 6.0));
    }

    public function test_report_no_readmissions_in_fixture(): void
    {
        $this->months(fn (AssertableInertia $p) => $p->where('months.1.readmits', 0));
    }
}
