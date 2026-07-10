<?php

namespace Tests\Feature;

use App\Http\Controllers\ImportController;
use App\Models\Admission;
use App\Models\Country;
use App\Models\Patient;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 4 — Item 5: ICD-10 code validation.
 *   - NEW admissions: every code must exist in icd10 (STRICT).
 *   - Modify: validate-only-on-change — unchanged dirty codes stay editable, a newly added code is
 *     validated.
 *   - Import: unknown codes are a per-row WARNING (not a rejection).
 *   - Orphan report: lists admission codes with no icd10 row.
 */
class Icd10ValidationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(array $extra = []): User
    {
        return User::create(array_merge([
            'username' => 'i10_' . substr(md5(uniqid('', true)), 0, 8),
            'name' => 'I10 Admin', 'role' => User::ROLE_ADMIN, 'active' => 1, 'password' => 'secret12345',
            'can_add' => 1, 'can_modify' => 1,
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
        ], $extra));
    }

    private function knownCode(string $code, string $name = 'Known Dx'): void
    {
        DB::table('icd10')->insert(['code' => $code, 'name' => $name]);
    }

    private function admitPayload(array $overrides = []): array
    {
        return array_merge([
            'mrn' => (string) random_int(100000, 999999), 'name' => 'New Pt', 'age' => 45,
            'gender' => 'Male', 'nationality' => 'Saudi Arabia', 'bed' => 'A1',
            'admit_date' => now()->toDateString(), 'admitted_from' => 'ER', 'current_location' => 'Ward',
            'diagnoses' => ['A00'],
        ], $overrides);
    }

    public function test_unknown_code_rejected_on_new_admission(): void
    {
        Country::firstOrCreate(['name' => 'Saudi Arabia'], ['code' => 'SA']);
        $this->knownCode('A00');

        $this->actingAs($this->admin())
            ->post('/admissions', $this->admitPayload(['diagnoses' => ['ZZZZZ']]))
            ->assertSessionHasErrors('diagnoses.0');
    }

    public function test_known_code_accepted_on_new_admission(): void
    {
        Country::firstOrCreate(['name' => 'Saudi Arabia'], ['code' => 'SA']);
        $this->knownCode('A00');

        $this->actingAs($this->admin())
            ->post('/admissions', $this->admitPayload(['diagnoses' => ['A00']]))
            ->assertRedirect()->assertSessionHasNoErrors();
    }

    /** Directly seed an admission with a known-bad (dirty) code, then modify unrelated fields. */
    private function dirtyAdmission(string $badCode = 'DIRTY99'): Admission
    {
        $p = Patient::create(['mrn' => (string) random_int(100000, 999999), 'name' => 'Dirty Pt', 'age' => 60, 'gender' => 'Female']);
        $a = Admission::create(['patient_id' => $p->id, 'admit_date' => now()->subDays(2)->toDateString(),
            'admitted_from' => 'ER', 'current_location' => 'Ward', 'bed' => 'B2']);
        DB::table('admission_diagnoses')->insert(['admission_id' => $a->id, 'seq' => 1, 'icd10_code' => $badCode,
            'created_at' => now(), 'updated_at' => now()]);

        return $a;
    }

    public function test_modify_with_unchanged_dirty_code_succeeds(): void
    {
        $a = $this->dirtyAdmission('DIRTY99');
        $p = $a->patient;

        // unchanged dirty code, only a bed change — must NOT be blocked by the missing icd10 row
        $this->actingAs($this->admin())->post("/admissions/{$a->id}/modify", [
            'mrn' => $p->mrn, 'name' => $p->name, 'age' => $p->age, 'gender' => $p->gender,
            'bed' => 'C3', 'admit_date' => $a->admit_date->toDateString(),
            'admitted_from' => $a->admitted_from, 'current_location' => $a->current_location,
            'diagnoses' => ['DIRTY99'],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('C3', $a->fresh()->bed);
    }

    public function test_modify_adding_unknown_code_fails(): void
    {
        $a = $this->dirtyAdmission('DIRTY99');
        $p = $a->patient;

        // adds a NEW unknown code on top of the unchanged dirty one — the added code is validated
        $this->actingAs($this->admin())->post("/admissions/{$a->id}/modify", [
            'mrn' => $p->mrn, 'name' => $p->name, 'age' => $p->age, 'gender' => $p->gender,
            'bed' => $a->bed, 'admit_date' => $a->admit_date->toDateString(),
            'admitted_from' => $a->admitted_from, 'current_location' => $a->current_location,
            'diagnoses' => ['DIRTY99', 'NOPE00'],
        ])->assertSessionHasErrors();
    }

    public function test_import_flags_unknown_codes_as_warning_not_rejection(): void
    {
        $this->knownCode('A00');
        $csv = "12345,Imp Pt,40,M,Saudi Arabia,2024-01-01,2024-01-05,Alive,Ward,A00|BADX99";

        $parse = new \ReflectionMethod(ImportController::class, 'parse');
        $parse->setAccessible(true);
        $rows = $parse->invoke(app(ImportController::class), $csv);

        $this->assertCount(1, $rows);
        $this->assertTrue($rows[0]['ok'], 'unknown ICD-10 is a warning, not a rejection');
        $this->assertStringContainsString('BADX99', (string) $rows[0]['warning']);
        $this->assertStringContainsString('Unknown ICD-10', (string) $rows[0]['warning']);
    }

    public function test_orphan_diagnoses_report_accessible(): void
    {
        $orphan = $this->dirtyAdmission('ORPHAN1');

        $this->actingAs($this->admin())->get('/admin/orphan-diagnoses')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/OrphanDiagnoses')
                ->where('orphans', fn ($o) => collect($o)->pluck('icd10_code')->contains('ORPHAN1')));
    }
}
