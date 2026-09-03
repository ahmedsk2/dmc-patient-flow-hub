<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\AdmissionDiagnosis;
use App\Models\AuditLog;
use App\Models\Consultation;
use App\Models\Patient;
use App\Models\User;
use App\Support\AuditDiff;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 2 — Item 4: the AuditDiff helper + its three controller callsites (patient.modify,
 * consultation.modify, user.update). The unit tests pin the {field:{from,to}} payload shape;
 * the feature tests confirm each modify path records that shape in its audit row.
 */
class AuditDiffTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'username' => 'ad_'.substr(md5(uniqid('', true)), 0, 8),
            'name' => 'Diff Admin', 'password' => 'secret12345', 'role' => User::ROLE_ADMIN, 'active' => 1,
            'can_modify' => 1, 'can_manage' => 1,
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
        ]);
    }

    // ---- unit: AuditDiff::diff -------------------------------------------------------------------

    public function test_diff_detects_changed_fields(): void
    {
        $this->assertSame(
            ['b' => ['from' => 2, 'to' => 3]],
            AuditDiff::diff(['a' => 1, 'b' => 2], ['a' => 1, 'b' => 3])
        );
    }

    public function test_diff_omits_unchanged_fields(): void
    {
        $this->assertSame([], AuditDiff::diff(['a' => 1], ['a' => 1]));
        // int 1 vs string "1" is NOT a change (string-cast comparison)
        $this->assertSame([], AuditDiff::diff(['a' => 1], ['a' => '1']));
    }

    public function test_diff_omits_excluded_keys(): void
    {
        $out = AuditDiff::diff(['password' => 'old', 'role' => 4], ['password' => 'new', 'role' => 0], ['password']);
        $this->assertArrayNotHasKey('password', $out);
        $this->assertArrayHasKey('role', $out);
    }

    public function test_diagnosis_diff_added_and_removed(): void
    {
        $this->assertSame(
            ['added' => ['A03'], 'removed' => ['A01']],
            AuditDiff::diagnosisDiff(['A01', 'A02'], ['A02', 'A03'])
        );
    }

    public function test_diagnosis_diff_returns_null_when_unchanged(): void
    {
        $this->assertNull(AuditDiff::diagnosisDiff(['A01', 'A02'], ['A02', 'A01']));
    }

    // ---- feature: callsite payloads -------------------------------------------------------------

    public function test_patient_modify_audit_contains_diff(): void
    {
        $admin = $this->admin();
        $p = Patient::create(['mrn' => '70000001', 'name' => 'Diff Pt', 'gender' => 'Male', 'age' => 40]);
        $a = Admission::create(['patient_id' => $p->id, 'admit_date' => '2024-01-10', 'bed' => 'A1',
            'current_location' => 'Ward', 'is_longterm' => 0, 'is_new_assignment' => 0]);

        $this->actingAs($admin)->post("/admissions/{$a->id}/modify", [
            'mrn' => '70000001', 'name' => 'Diff Pt', 'age' => 40, 'gender' => 'Male',
            'bed' => 'B2', 'admit_date' => '2024-01-12', 'current_location' => 'Ward',
            'diagnoses' => [],
        ])->assertRedirect();

        $details = AuditLog::where('action', 'patient.modify')->latest('id')->first()->details;
        $this->assertArrayHasKey('bed', $details);
        $this->assertSame('A1', $details['bed']['from']);
        $this->assertSame('B2', $details['bed']['to']);
        $this->assertArrayHasKey('admit_date', $details);
        $this->assertSame('2024-01-10', $details['admit_date']['from']);
        $this->assertSame('2024-01-12', $details['admit_date']['to']);
    }

    public function test_patient_modify_audit_contains_diagnosis_diff(): void
    {
        $admin = $this->admin();
        $p = Patient::create(['mrn' => '70000009', 'name' => 'Dx Pt']);
        $a = Admission::create(['patient_id' => $p->id, 'admit_date' => '2024-01-10',
            'current_location' => 'Ward', 'is_longterm' => 0, 'is_new_assignment' => 0]);
        AdmissionDiagnosis::create(['admission_id' => $a->id, 'seq' => 1, 'icd10_code' => 'A01']);
        DB::table('icd10')->insert(['code' => 'A02', 'name' => 'Typhoid']);   // Phase 4 — Item 5: added code must exist

        $this->actingAs($admin)->post("/admissions/{$a->id}/modify", [
            'mrn' => '70000009', 'name' => 'Dx Pt', 'admit_date' => '2024-01-10', 'current_location' => 'Ward',
            'diagnoses' => ['A02'],   // remove A01, add A02
        ])->assertRedirect();

        $details = AuditLog::where('action', 'patient.modify')->latest('id')->first()->details;
        $this->assertArrayHasKey('diagnoses', $details);
        $this->assertSame(['A02'], $details['diagnoses']['added']);
        $this->assertSame(['A01'], $details['diagnoses']['removed']);
    }

    public function test_consultation_update_audit_contains_diff(): void
    {
        $admin = $this->admin();
        $c = Consultation::create(['mrn' => '70000002', 'patient_name' => 'Cons Pt', 'age' => 50,
            'bed' => 'C1', 'current_location' => 'Ward', 'consultation_date' => '2024-04-01',
            'consultation_from' => 'IM', 'to_service' => 'Cardiology', 'indication' => [1]]);

        $this->actingAs($admin)->put("/consultations/{$c->id}", [
            'mrn' => '70000002', 'patient_name' => 'Cons Pt', 'age' => 50, 'bed' => 'C1',
            'current_location' => 'Ward', 'consultation_date' => '2024-04-01',
            'consultation_from' => 'IM', 'to_service' => 'Neurology',   // changed; free-text => consultant_id nullable
            'indication' => [1],
        ])->assertRedirect();

        $details = AuditLog::where('action', 'consultation.modify')->latest('id')->first()->details;
        $this->assertArrayHasKey('to_service', $details);
        $this->assertSame('Cardiology', $details['to_service']['from']);
        $this->assertSame('Neurology', $details['to_service']['to']);
    }

    public function test_user_update_audit_contains_diff(): void
    {
        $admin = $this->admin();
        $target = User::create([
            'username' => 'tgt_'.substr(md5(uniqid('', true)), 0, 8),
            'full_name' => 'Target User', 'name' => 'Target User',
            'password' => 'secret12345', 'role' => User::ROLE_RESIDENT, 'active' => 1,
        ]);

        $this->actingAs($admin)->put("/control/users/{$target->id}", [
            'username' => $target->username, 'full_name' => 'Target User', 'email' => null,
            'role' => User::ROLE_CONSULTANT,   // changed 4 -> 3
            'active' => 1, 'on_service' => 0, 'specialty_id' => null,
            'can_assign' => 0, 'can_add' => 0, 'can_manage' => 1, 'can_modify' => 0,   // can_manage 0 -> 1
        ])->assertRedirect();

        $details = AuditLog::where('action', 'user.update')->latest('id')->first()->details;
        $this->assertArrayHasKey('role', $details);
        $this->assertSame(4, (int) $details['role']['from']);
        $this->assertSame(3, (int) $details['role']['to']);
        $this->assertArrayHasKey('can_manage', $details);
        // unchanged fields (username) are NOT recorded
        $this->assertArrayNotHasKey('username', $details);
    }
}
