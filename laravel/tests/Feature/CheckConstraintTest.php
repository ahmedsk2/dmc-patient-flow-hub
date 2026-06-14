<?php

namespace Tests\Feature;

use App\Http\Controllers\ImportController;
use App\Models\Patient;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 4 — Item 7: structural CHECK constraints (date ordering + age range) and the matching
 * import-pipeline rejections/warnings. The DB-level checks make impossible data unstorable
 * regardless of code path; the import rules reject date inversions and warn on outcome/destination
 * + delay-reason inconsistencies before commit.
 */
class CheckConstraintTest extends TestCase
{
    use RefreshDatabase;

    private function patientId(): int
    {
        return Patient::create(['mrn' => (string) random_int(100000, 999999), 'name' => 'Chk Pt', 'gender' => 'Male'])->id;
    }

    private function parse(string $csv): array
    {
        $m = new \ReflectionMethod(ImportController::class, 'parse');
        $m->setAccessible(true);

        return $m->invoke(app(ImportController::class), $csv);
    }

    // ---- DB CHECK constraints -------------------------------------------------------------------

    public function test_check_constraint_blocks_discharge_before_admit(): void
    {
        $this->expectException(QueryException::class);
        DB::table('admissions')->insert([
            'patient_id' => $this->patientId(), 'current_location' => 'Ward',
            'admit_date' => '2024-05-10', 'discharge_date' => '2024-05-01',   // before admit
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_check_constraint_blocks_medical_discharge_before_admit(): void
    {
        $this->expectException(QueryException::class);
        DB::table('admissions')->insert([
            'patient_id' => $this->patientId(), 'current_location' => 'Ward',
            'admit_date' => '2024-05-10', 'medical_discharge_date' => '2024-05-01',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_check_constraint_blocks_impossible_age(): void
    {
        $this->expectException(QueryException::class);
        DB::table('patients')->insert(['mrn' => '991122', 'name' => 'Old', 'age' => 151,
            'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_check_constraint_allows_null_discharge(): void
    {
        $id = DB::table('admissions')->insertGetId([
            'patient_id' => $this->patientId(), 'current_location' => 'Ward',
            'admit_date' => '2024-05-10', 'discharge_date' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertDatabaseHas('admissions', ['id' => $id]);
    }

    // ---- import-pipeline rules ------------------------------------------------------------------

    public function test_import_rejects_date_inversion(): void
    {
        $rows = $this->parse('12345,Inv Pt,40,M,Saudi Arabia,2024-05-10,2024-05-01,Alive,Ward');
        $this->assertFalse($rows[0]['ok']);
        $this->assertStringContainsString('before admit', (string) $rows[0]['error']);
    }

    public function test_import_warns_dead_to_non_mortuary(): void
    {
        // columns: MRN,Name,Age,Gender,Nat,Admit,Discharge,Outcome,Location,Dx,Consultant,DischargedTo
        $rows = $this->parse('12345,Dead Pt,70,M,Saudi Arabia,2024-05-01,2024-05-05,Dead,Ward,,,Home');
        $this->assertTrue($rows[0]['ok']);
        $this->assertStringContainsString('Mortuary', (string) $rows[0]['warning']);
    }

    public function test_import_warns_medical_discharge_without_delay_reason(): void
    {
        // ClinicalDischargeDate (col 13) set, no DischargeDate (col 7), no DelayReason (col 16)
        $rows = $this->parse('12345,Phase1 Pt,55,F,Saudi Arabia,2024-05-01,,Alive,Ward,,,,2024-05-04');
        $this->assertTrue($rows[0]['ok']);
        $this->assertStringContainsString('delay reason', (string) $rows[0]['warning']);
    }
}
