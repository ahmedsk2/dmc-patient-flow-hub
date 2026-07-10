<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\AuditLog;
use App\Models\Patient;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Gap Wave 1 server-side behavior: undo of a phase-1 medical discharge (board "Reverse"
 * previously hit reverseDischarge, which rejects active rows) and the dedicated ICU-pull
 * endpoint (decision D2 = legacy: Add capability, new ward episode is unassigned).
 */
class GapWave1Test extends TestCase
{
    use RefreshDatabase;

    private function user(array $overrides = []): User
    {
        return User::create(array_merge([
            'username' => 'gw_' . substr(md5(uniqid('', true)), 0, 8),
            'name' => 'GW User', 'password' => 'secret12345', 'role' => User::ROLE_CONSULTANT, 'active' => 1,
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
        ], $overrides));
    }

    private function admission(array $overrides = []): Admission
    {
        $p = Patient::create(['mrn' => (string) random_int(10000000, 99999999), 'name' => 'GW Patient']);

        return Admission::create(array_merge([
            'patient_id' => $p->id, 'admit_date' => now()->subDays(3)->toDateString(),
            'current_location' => 'Ward', 'is_longterm' => 0, 'is_new_assignment' => 0,
        ], $overrides));
    }

    public function test_owning_consultant_can_undo_medical_discharge(): void
    {
        $c = $this->user(['name' => 'Owner Cons']);   // owner, no can_manage
        $a = $this->admission([
            'consultant_id' => $c->id, 'medical_discharge_date' => now()->toDateString(),
            'outcome' => 'Alive', 'discharge_to' => 'Home', 'delay_reason' => 'bed', 'discharged_by' => $c->id,
        ]);

        $this->actingAs($c)->post("/admissions/{$a->id}/undo-medical-discharge")->assertRedirect();

        $a->refresh();
        $this->assertNull($a->medical_discharge_date);
        $this->assertNull($a->outcome);
        $this->assertNull($a->discharge_to);
        $this->assertNull($a->delay_reason);
        $this->assertNull($a->discharged_by);
        $this->assertNull($a->discharge_date, 'the row stays active');
        $this->assertTrue(AuditLog::where('action', 'admission.undo_medical_discharge')->where('entity_id', (string) $a->id)->exists());
    }

    public function test_non_owner_without_manage_cannot_undo_medical_discharge(): void
    {
        $owner = $this->user(['name' => 'Owner Cons']);
        $other = $this->user(['name' => 'Other Cons']);   // not the owner, no can_manage
        $a = $this->admission([
            'consultant_id' => $owner->id, 'medical_discharge_date' => now()->toDateString(), 'outcome' => 'Alive',
        ]);

        $this->actingAs($other)->post("/admissions/{$a->id}/undo-medical-discharge")->assertForbidden();
        $this->assertNotNull($a->fresh()->medical_discharge_date);
    }

    public function test_undo_medical_discharge_rejects_fully_discharged(): void
    {
        $admin = $this->user(['role' => User::ROLE_ADMIN]);
        $a = $this->admission([
            'medical_discharge_date' => now()->toDateString(), 'discharge_date' => now()->toDateString(),
            'outcome' => 'Alive', 'transfer_type' => 'discharge from ward',
        ]);

        $this->actingAs($admin)->post("/admissions/{$a->id}/undo-medical-discharge")->assertRedirect();

        $a->refresh();
        $this->assertNotNull($a->discharge_date, 'a closed file is out of scope for the medical-discharge undo');
        $this->assertNotNull($a->medical_discharge_date);
        $this->assertSame('Alive', $a->outcome);
    }

    public function test_can_add_user_can_pull_patient_from_icu(): void
    {
        $resident = $this->user(['role' => User::ROLE_RESIDENT, 'can_add' => 1]);   // no can_manage
        $icuCons = $this->user(['name' => 'ICU Cons']);
        $a = $this->admission(['current_location' => 'ICU', 'consultant_id' => $icuCons->id, 'bed' => 'ICU-3']);
        $a->diagnoses()->create(['seq' => 1, 'icd10_code' => 'A15.0']);
        $a->diagnoses()->create(['seq' => 2, 'icd10_code' => 'J18.9']);

        $this->actingAs($resident)->post("/admissions/{$a->id}/icu-pull")->assertRedirect();

        $a->refresh();
        $this->assertNotNull($a->discharge_date, 'the ICU episode is closed');
        $this->assertSame('Transfer from ICU', $a->transfer_type);
        $this->assertSame($resident->id, (int) $a->discharged_by);

        $new = Admission::where('patient_id', $a->patient_id)->whereNull('discharge_date')->first();
        $this->assertNotNull($new, 'a new ward episode is opened');
        $this->assertSame('Ward', $new->current_location);
        $this->assertSame('ICU', $new->admitted_from);
        $this->assertNull($new->consultant_id, 'legacy D2: the patient re-enters the assignment queue');
        $this->assertFalse($new->is_new_assignment);
        $this->assertNull($new->assigned_at);
        $this->assertNull($new->bed);
        $this->assertSame(['A15.0', 'J18.9'], $new->diagnoses()->orderBy('seq')->pluck('icd10_code')->all(), 'diagnoses carried forward');
        $this->assertTrue(AuditLog::where('action', 'admission.icu_pull')->where('entity_id', (string) $a->id)->exists());
    }

    public function test_icu_pull_requires_add_capability(): void
    {
        $resident = $this->user(['role' => User::ROLE_RESIDENT]);   // neither admin nor can_add
        $a = $this->admission(['current_location' => 'ICU']);

        $this->actingAs($resident)->post("/admissions/{$a->id}/icu-pull")->assertForbidden();
        $this->assertNull($a->fresh()->discharge_date);
    }

    public function test_icu_pull_rejects_non_icu_admission(): void
    {
        $admin = $this->user(['role' => User::ROLE_ADMIN]);
        $a = $this->admission(['current_location' => 'Ward']);

        $this->actingAs($admin)->post("/admissions/{$a->id}/icu-pull")->assertRedirect();

        $this->assertNull($a->fresh()->discharge_date, 'a ward patient cannot be ICU-pulled');
        $this->assertSame(1, Admission::where('patient_id', $a->patient_id)->count(), 'no second episode is created');
    }
}
