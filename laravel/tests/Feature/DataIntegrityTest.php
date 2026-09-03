<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\AdmissionDiagnosis;
use App\Models\Country;
use App\Models\Patient;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Guards that prevent the data-quality issues found in the legacy data from recurring:
 * blank/whitespace/non-digit MRN, non-existent consultant, and duplicate diagnosis codes.
 */
class DataIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'username' => 'di_'.substr(md5(uniqid('', true)), 0, 8),
            'name' => 'DI Admin', 'password' => 'secret12345', 'role' => User::ROLE_ADMIN, 'active' => 1,
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
        ]);
    }

    private function payload(array $overrides = []): array
    {
        // B1 (legacy Fill-All): age/gender/nationality/bed + ≥1 diagnosis are REQUIRED on admit
        Country::firstOrCreate(['name' => 'Saudi Arabia'], ['code' => 'SA']);
        // Phase 4 — Item 5: the diagnosis code must exist in the icd10 reference table
        DB::table('icd10')->updateOrInsert(['code' => 'J18.9'], ['name' => 'Pneumonia']);

        return array_merge([
            'mrn' => '12345', 'name' => 'Test Patient', 'age' => 50, 'gender' => 'Male',
            'nationality' => 'Saudi Arabia', 'bed' => 'W-1',
            'current_location' => 'Ward', 'admit_date' => now()->toDateString(), 'diagnoses' => ['J18.9'],
        ], $overrides);
    }

    public function test_whitespace_around_mrn_is_trimmed_on_admit(): void
    {
        $this->actingAs($this->admin())->post('/admissions', $this->payload(['mrn' => '  12345 ']))->assertRedirect();
        $this->assertDatabaseHas('patients', ['mrn' => '12345']);
        $this->assertDatabaseMissing('patients', ['mrn' => '  12345 ']);
    }

    public function test_blank_mrn_is_rejected(): void
    {
        $this->actingAs($this->admin())->post('/admissions', $this->payload(['mrn' => '   ']))->assertSessionHasErrors('mrn');
        $this->assertDatabaseCount('patients', 0);
    }

    public function test_non_digit_mrn_is_rejected(): void
    {
        $this->actingAs($this->admin())->post('/admissions', $this->payload(['mrn' => '12A45']))->assertSessionHasErrors('mrn');
    }

    public function test_nonexistent_consultant_is_rejected(): void
    {
        $this->actingAs($this->admin())->post('/admissions', $this->payload(['consultant_id' => 999999]))->assertSessionHasErrors('consultant_id');
    }

    public function test_duplicate_diagnosis_codes_are_deduped_on_admit(): void
    {
        $this->actingAs($this->admin())->post('/admissions', $this->payload(['mrn' => '22222', 'diagnoses' => ['J18.9', 'J18.9', ' J18.9 ']]))->assertRedirect();
        $this->assertDatabaseCount('admission_diagnoses', 1);
    }

    public function test_unique_index_blocks_duplicate_diagnosis_rows(): void
    {
        $p = Patient::create(['mrn' => '33333', 'name' => 'X']);
        $a = Admission::create(['patient_id' => $p->id, 'admit_date' => now()->toDateString(), 'current_location' => 'Ward', 'is_longterm' => 0, 'is_new_assignment' => 0]);
        AdmissionDiagnosis::create(['admission_id' => $a->id, 'seq' => 1, 'icd10_code' => 'A00']);

        $this->expectException(QueryException::class);
        AdmissionDiagnosis::create(['admission_id' => $a->id, 'seq' => 2, 'icd10_code' => 'A00']);
    }
}
