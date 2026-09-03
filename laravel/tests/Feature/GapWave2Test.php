<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\Patient;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Gap Wave 2 — legacy A4 LANDSCAPE report booklets. VALUE-checks the new gather() metric
 * families against a hand-computed fixture (via the /reports Inertia props, which spread
 * gather() verbatim) and smoke-checks that both PDF routes stream real %PDF bytes.
 *
 * Fixture (year 2024; June 2024: the 1st is a SATURDAY → 06-07 Fri, 06-08 Sat, 06-09 Sun;
 * readmission window = default 3 days):
 *   A1  Ward admit 06-02, med-disch 06-05, disch 06-07 (FRI), 'discharge from ward', to Home
 *   A2  Ward admit 06-03,               disch 06-08 (SAT), 'other transfer', to Intensive Care (ICU)
 *   A3  Ward admit 06-04,               disch 06-09 (SUN), 'discharge from ward', to Home
 *   A4  Ward admit 06-09 = P1 again, 2 days after A1's REAL discharge → 72-hr readmission; active
 *
 * Hand-computed June (months[5]):
 *   weekend = 2 (Fri+Sat; the SUNDAY discharge must NOT count — D4) of 3 discharges = 66.67%
 *   transToIcu = 1 (A2) · readmits = 1 (A4) · clinicalLos = 3.00 (A1 only) · physicalLos = 5.00
 *   bedDays = 6 (A1) + 6 (A2) + 6 (A3) + 22 (A4 admitted 06-09, active through 06-30) = 40
 *   census = 4; legacy totalPatients = 4 + A4 active Jul..Dec (1 × 6) = 10
 *   legacy longStay = 6 (A4 active and admitted >30 days before each month-end Jul..Dec) → 60.00%
 */
#[Group('pdf')]
class GapWave2Test extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'username' => 'g2_'.substr(md5(uniqid('', true)), 0, 8),
            'name' => 'G2 Admin', 'password' => 'secret12345', 'role' => User::ROLE_ADMIN, 'active' => 1, 'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
        ]);
    }

    private function seedFixture(): void
    {
        // one active consultant with NO discharges — must still appear in the per-consultant
        // LOS chart series with a zero (legacy chart covers ALL active consultants)
        User::create([
            'username' => 'g2_cons', 'name' => 'g2cons', 'full_name' => 'Dr Zero',
            'password' => 'secret12345', 'role' => User::ROLE_CONSULTANT, 'active' => 1, 'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
        ]);

        $p1 = Patient::create(['mrn' => '71000001', 'name' => 'G2 One']);
        $p2 = Patient::create(['mrn' => '71000002', 'name' => 'G2 Two']);
        $p3 = Patient::create(['mrn' => '71000003', 'name' => 'G2 Three']);

        $base = ['current_location' => 'Ward', 'is_longterm' => 0, 'is_new_assignment' => 0];
        // A1 — Friday discharge, phase-1 medical discharge on 06-05 (clinical LOS 3, physical 5)
        Admission::create([...$base, 'patient_id' => $p1->id, 'admit_date' => '2024-06-02',
            'medical_discharge_date' => '2024-06-05', 'discharge_date' => '2024-06-07',
            'outcome' => 'Alive', 'transfer_type' => 'discharge from ward', 'discharge_to' => 'Home']);
        // A2 — Saturday discharge, destination ICU (the transToIcu bucket)
        Admission::create([...$base, 'patient_id' => $p2->id, 'admit_date' => '2024-06-03',
            'discharge_date' => '2024-06-08', 'outcome' => 'Alive',
            'transfer_type' => 'other transfer', 'discharge_to' => 'Intensive Care (ICU)']);
        // A3 — SUNDAY discharge: a legacy Sat/Sun "weekend" would count it; Fri/Sat (D4) must not
        Admission::create([...$base, 'patient_id' => $p3->id, 'admit_date' => '2024-06-04',
            'discharge_date' => '2024-06-09', 'outcome' => 'Alive',
            'transfer_type' => 'discharge from ward', 'discharge_to' => 'Home']);
        // A4 — 72-hr readmission of P1 (2-day gap after a REAL ward discharge), still active
        Admission::create([...$base, 'patient_id' => $p1->id, 'admit_date' => '2024-06-09']);
    }

    public function test_annual_report_legacy_metric_families_match_hand_computed_fixture(): void
    {
        $this->seedFixture();

        $this->actingAs($this->admin())
            ->get('/reports?year=2024')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p
                // June = months[5]
                ->where('months.5.weekend', 2)                                       // Fri 06-07 + Sat 06-08, NOT Sun 06-09 (D4)
                ->where('months.5.weekendPct', 66.67)                                // 2 of 3 June discharges
                ->where('months.5.transToIcu', 1)                                    // A2 → Intensive Care (ICU)
                ->where('months.5.readmits', 1)                                      // A4 (window 3d, REAL-discharge anchor)
                ->where('months.5.clinicalLos', fn ($v) => (float) $v === 3.0)       // A1: 06-02 → med 06-05
                ->where('months.5.physicalLos', fn ($v) => (float) $v === 5.0)       // (5+5+5)/3
                ->where('months.5.bedDays', 40)                                      // 6+6+6+22 (overlap sums)
                ->where('months.5.census', 4)                                        // all four episodes present in June
                ->where('months.5.longStayCensus', 0)
                // a month with no activity stays zeroed (May = months[4])
                ->where('months.4.weekend', 0)->where('months.4.readmits', 0)
                // legacy page-2 counter cards (census-based — D5)
                ->where('legacy.totalPatients', 10)                                  // 4 (June) + 1 active × Jul..Dec
                ->where('legacy.longStay', 6)                                        // A4 >30d before each month-end Jul..Dec
                ->where('legacy.longStayPct', fn ($v) => (float) $v === 60.0)        // JSON may drop the .0
                ->where('legacy.weekend', 2)
                ->where('legacy.weekendPct', 66.67)
                ->where('legacy.readmissions', 1)
                // destination doughnut buckets (fixed legacy 7, zeros kept)
                ->where('legacy.destinations.Home', 2)
                ->where('legacy.destinations.Intensive Care (ICU)', 1)
                ->where('legacy.destinations.Mortuary', 0)
                // per-consultant LOS covers ALL active consultants, zeros included
                ->where('legacy.consultantLos.0.name', 'Dr Zero')
                ->where('legacy.consultantLos.0.value', fn ($v) => (float) $v === 0.0)
                // pre-existing reconciled headline formulas must NOT change
                ->where('totals.admissions', 4)
                ->where('totals.discharges', 3)
                ->where('totals.deaths', 0)
            );
    }

    public function test_annual_pdf_route_streams_a_real_pdf(): void
    {
        $this->seedFixture();

        $resp = $this->actingAs($this->admin())->get('/reports/pdf?year=2024');
        $resp->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $resp->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $resp->getContent());
        $this->assertGreaterThan(100_000, strlen($resp->getContent()), 'landscape booklet with banners + charts');
    }

    public function test_monthly_pdf_route_streams_the_full_year_booklet(): void
    {
        $this->seedFixture();

        $resp = $this->actingAs($this->admin())->get('/reports/monthly/pdf?year=2024');
        $resp->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $resp->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $resp->getContent());
        // 12 elapsed months × 2 landscape pages (exclude the /Pages tree node from the count)
        $this->assertSame(24, preg_match_all('~/Type\s*/Page[^s]~', $resp->getContent()));
    }
}
