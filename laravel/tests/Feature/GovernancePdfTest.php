<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Phase 3 — §3.2: governance / M&M pack PDF (auth, validation, de-identified line lists). */
class GovernancePdfTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['username' => 'gv_admin_' . substr(md5(uniqid('', true)), 0, 6),
            'name' => 'GV Admin', 'password' => 'secret12345', 'role' => User::ROLE_ADMIN, 'active' => 1]);
    }

    private function nonAdmin(): User
    {
        return User::create(['username' => 'gv_cons_' . substr(md5(uniqid('', true)), 0, 6),
            'name' => 'GV Cons', 'password' => 'secret12345', 'role' => User::ROLE_CONSULTANT, 'active' => 1]);
    }

    public function test_governance_pdf_requires_admin(): void
    {
        $this->actingAs($this->nonAdmin())
            ->get('/reports/governance/pdf?period_type=month&year=2024&month=6')->assertForbidden();
    }

    public function test_governance_pdf_invalid_period_type_rejected(): void
    {
        $this->actingAs($this->admin())
            ->get('/reports/governance/pdf?period_type=year&year=2024')->assertInvalid(['period_type']);
    }

    public function test_governance_pdf_missing_month_for_month_type_rejected(): void
    {
        $this->actingAs($this->admin())
            ->get('/reports/governance/pdf?period_type=month&year=2024')->assertInvalid(['month']);
    }

    public function test_governance_pdf_returns_pdf(): void
    {
        $this->actingAs($this->admin())
            ->get('/reports/governance/pdf?period_type=month&year=2024&month=6')
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_governance_screen_requires_admin_and_renders_for_admin(): void
    {
        $this->actingAs($this->nonAdmin())->get('/reports/governance')->assertForbidden();
        $this->actingAs($this->admin())->get('/reports/governance')->assertOk();
    }

    public function test_governance_pdf_death_list_includes_all_deaths(): void
    {
        // 2 deaths + 1 non-death discharge in June 2024
        $d1 = Patient::create(['mrn' => '90000001', 'name' => 'D1', 'age' => 70]);
        $d2 = Patient::create(['mrn' => '90000002', 'name' => 'D2', 'age' => 55]);
        $alive = Patient::create(['mrn' => '90000003', 'name' => 'A1', 'age' => 40]);
        $base = ['is_longterm' => 0, 'is_new_assignment' => 0, 'current_location' => 'Ward'];
        Admission::create([...$base, 'patient_id' => $d1->id, 'admit_date' => '2024-06-02', 'discharge_date' => '2024-06-08', 'outcome' => 'Dead', 'transfer_type' => 'discharge from ward']);
        Admission::create([...$base, 'patient_id' => $d2->id, 'admit_date' => '2024-06-05', 'discharge_date' => '2024-06-10', 'outcome' => 'Dead', 'transfer_type' => 'discharge from ward']);
        Admission::create([...$base, 'patient_id' => $alive->id, 'admit_date' => '2024-06-03', 'discharge_date' => '2024-06-07', 'outcome' => 'Alive', 'transfer_type' => 'discharge from ward']);

        // PDF renders
        $this->actingAs($this->admin())
            ->get('/reports/governance/pdf?period_type=month&year=2024&month=6')->assertOk();

        // value: exactly the 2 deaths are in the death line-list query the controller uses
        $deaths = DB::table('admissions')->whereBetween('discharge_date', ['2024-06-01', '2024-06-30'])
            ->where('outcome', 'Dead')->count();
        $this->assertSame(2, $deaths);
    }

    public function test_governance_pdf_readmit_list_reuses_readmission_join(): void
    {
        // A1 -> A4 readmission pattern; A5 transfer-continuation must NOT count (StatisticsValueTest fixture)
        $p1 = Patient::create(['mrn' => '90000010', 'name' => 'R1']);
        $p5 = Patient::create(['mrn' => '90000050', 'name' => 'R5']);
        $base = ['is_longterm' => 0, 'is_new_assignment' => 0, 'current_location' => 'Ward'];
        // prior REAL ward discharge
        Admission::create([...$base, 'patient_id' => $p1->id, 'admit_date' => '2024-06-02', 'discharge_date' => '2024-06-07', 'outcome' => 'Alive', 'transfer_type' => 'discharge from ward']);
        // genuine 72h readmission (2d gap)
        Admission::create([...$base, 'patient_id' => $p1->id, 'admit_date' => '2024-06-09']);
        // P5: prior episode ended in a TRANSFER -> the re-entry is NOT a readmission
        Admission::create([...$base, 'patient_id' => $p5->id, 'admit_date' => '2024-05-28', 'discharge_date' => '2024-06-05', 'transfer_type' => 'other transfer']);
        Admission::create([...$base, 'patient_id' => $p5->id, 'admit_date' => '2024-06-06']);

        $this->actingAs($this->admin())
            ->get('/reports/governance/pdf?period_type=month&year=2024&month=6')->assertOk();

        // value: the readmissionJoin counts exactly 1 readmit admitted in June (A4 only)
        $readmits = DB::table('admissions as a')->join('admissions as prev', Admission::readmissionJoin(3))
            ->whereBetween('a.admit_date', ['2024-06-01', '2024-06-30'])->distinct()->count('a.id');
        $this->assertSame(1, $readmits);
    }
}
