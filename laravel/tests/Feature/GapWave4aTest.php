<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\AuditLog;
use App\Models\Patient;
use App\Models\Specialty;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Gap Wave 4a: three-mode transfer (location / internal specialty / external service),
 * modify gaining admit_date+admitted_from+current_location (data correction, audited),
 * admin-only hard delete of an admission, and D1 consultant board scoping.
 */
class GapWave4aTest extends TestCase
{
    use RefreshDatabase;

    private function user(array $overrides = []): User
    {
        return User::create(array_merge([
            'username' => 'g4_' . substr(md5(uniqid('', true)), 0, 8),
            'name' => 'G4 User', 'password' => 'secret12345', 'role' => User::ROLE_CONSULTANT, 'active' => 1,
        ], $overrides));
    }

    private function admission(array $overrides = []): Admission
    {
        $p = Patient::create(['mrn' => (string) random_int(10000000, 99999999), 'name' => 'G4 Patient']);

        return Admission::create(array_merge([
            'patient_id' => $p->id, 'admit_date' => now()->subDays(4)->toDateString(),
            'current_location' => 'Ward', 'is_longterm' => 0, 'is_new_assignment' => 0,
        ], $overrides));
    }

    // ---- 1. three-mode transfer -------------------------------------------------------------

    public function test_specialty_transfer_closes_old_episode_and_opens_new_under_chosen_consultant(): void
    {
        $admin = $this->user(['role' => User::ROLE_ADMIN]);
        $spec = Specialty::create(['name' => 'Cardiology', 'is_subspecialty' => true]);
        $cardio = $this->user(['name' => 'Cardio Cons', 'specialty_id' => $spec->id]);
        $owner = $this->user();
        $a = $this->admission(['consultant_id' => $owner->id, 'bed' => 'W-12',
            'medical_discharge_date' => now()->toDateString(), 'outcome' => 'Alive', 'delay_reason' => 'bed']);
        $a->diagnoses()->create(['seq' => 1, 'icd10_code' => 'I21.0']);
        $a->diagnoses()->create(['seq' => 2, 'icd10_code' => 'E11.9']);
        // a consultant-changing transfer requires a handover updated TODAY (HandoverTest covers the gate)
        \App\Models\Handover::create(['admission_id' => $a->id, 'body' => 'Plan attached.', 'updated_by' => $owner->id]);

        $this->actingAs($admin)->post("/admissions/{$a->id}/transfer", [
            'mode' => 'specialty', 'specialty_id' => $spec->id, 'consultant_id' => $cardio->id,
        ])->assertRedirect();

        $a->refresh();
        $this->assertSame(now()->toDateString(), $a->discharge_date->toDateString());
        $this->assertSame('transfer to other speciality', $a->transfer_type);
        $this->assertSame('Cardiology', $a->discharge_to);
        $this->assertNull($a->medical_discharge_date, 'pending phase-1 discharge is superseded');
        $this->assertSame('Alive', $a->outcome, 'H1 revert: legacy stamps MORTALITY=Alive on transfer-closed rows');
        $this->assertNull($a->delay_reason);
        $this->assertSame($admin->id, (int) $a->discharged_by);

        $new = Admission::where('patient_id', $a->patient_id)->whereNull('discharge_date')->first();
        $this->assertNotNull($new, 'a new episode is opened for the receiving specialty');
        $this->assertSame($cardio->id, (int) $new->consultant_id, 'new episode belongs to the CHOSEN consultant');
        $this->assertSame('Ward', $new->current_location, 'location is unchanged by a specialty handover');
        $this->assertNull($new->bed);
        $this->assertSame('Transfer', $new->admitted_from);
        $this->assertTrue($new->is_new_assignment, 'a specialty handover IS a new assignment');
        $this->assertNotNull($new->assigned_at);
        $this->assertSame($admin->id, (int) $new->admitted_by);
        $this->assertSame(['I21.0', 'E11.9'], $new->diagnoses()->orderBy('seq')->pluck('icd10_code')->all(), 'diagnoses carried forward');
        $this->assertTrue(AuditLog::where('action', 'admission.transfer_specialty')->where('entity_id', (string) $a->id)->exists());
    }

    public function test_specialty_transfer_rejects_non_consultant_target(): void
    {
        $admin = $this->user(['role' => User::ROLE_ADMIN]);
        $spec = Specialty::create(['name' => 'Nephrology', 'is_subspecialty' => true]);
        $resident = $this->user(['role' => User::ROLE_RESIDENT]);
        $a = $this->admission();

        $this->actingAs($admin)->post("/admissions/{$a->id}/transfer", [
            'mode' => 'specialty', 'specialty_id' => $spec->id, 'consultant_id' => $resident->id,
        ])->assertSessionHasErrors('consultant_id');

        $this->assertNull($a->fresh()->discharge_date);
        $this->assertSame(1, Admission::where('patient_id', $a->patient_id)->count());
    }

    public function test_external_transfer_closes_episode_without_new_one(): void
    {
        $admin = $this->user(['role' => User::ROLE_ADMIN]);
        Specialty::create(['name' => 'Psychiatry (external)', 'is_subspecialty' => true, 'is_external' => true]);
        $a = $this->admission(['medical_discharge_date' => now()->toDateString(), 'outcome' => 'Alive']);

        $this->actingAs($admin)->post("/admissions/{$a->id}/transfer", [
            'mode' => 'external', 'service' => 'Psychiatry (external)',
        ])->assertRedirect();

        $a->refresh();
        $this->assertSame(now()->toDateString(), $a->discharge_date->toDateString());
        $this->assertSame('other transfer', $a->transfer_type);
        $this->assertSame('Psychiatry (external)', $a->discharge_to);
        $this->assertNull($a->medical_discharge_date);
        $this->assertSame('Alive', $a->outcome, 'H1 revert: legacy stamps MORTALITY=Alive on transfer-closed rows');
        $this->assertSame($admin->id, (int) $a->discharged_by);
        $this->assertSame(1, Admission::where('patient_id', $a->patient_id)->count(), 'NO new episode for an external transfer');
        $this->assertTrue(AuditLog::where('action', 'admission.transfer_external')->where('entity_id', (string) $a->id)->exists());
    }

    public function test_external_transfer_requires_known_external_service(): void
    {
        $admin = $this->user(['role' => User::ROLE_ADMIN]);
        Specialty::create(['name' => 'Cardiology', 'is_subspecialty' => true]);   // internal — not a valid target
        $a = $this->admission();

        $this->actingAs($admin)->post("/admissions/{$a->id}/transfer", [
            'mode' => 'external', 'service' => 'Cardiology',
        ])->assertSessionHasErrors('service');

        $this->assertNull($a->fresh()->discharge_date);
    }

    public function test_legacy_location_transfer_payload_still_works(): void
    {
        $admin = $this->user(['role' => User::ROLE_ADMIN]);
        $cons = $this->user(['name' => 'Keep Cons']);
        $a = $this->admission(['consultant_id' => $cons->id, 'bed' => 'W-3']);
        $a->diagnoses()->create(['seq' => 1, 'icd10_code' => 'J18.9']);

        // no `mode` key — the pre-wave payload must keep its exact behavior
        $this->actingAs($admin)->post("/admissions/{$a->id}/transfer", ['target' => 'ICU'])->assertRedirect();

        $a->refresh();
        $this->assertNotNull($a->discharge_date);
        $this->assertSame('other transfer', $a->transfer_type);

        $new = Admission::where('patient_id', $a->patient_id)->whereNull('discharge_date')->first();
        $this->assertNotNull($new);
        $this->assertSame('ICU', $new->current_location);
        $this->assertSame($cons->id, (int) $new->consultant_id, 'ward<->ICU keeps the consultant');
        $this->assertSame(['J18.9'], $new->diagnoses()->pluck('icd10_code')->all());
    }

    // ---- 2. modify gains admit_date / admitted_from / current_location -----------------------

    public function test_modify_updates_admit_date_admitted_from_and_location_with_audited_old_date(): void
    {
        $admin = $this->user(['role' => User::ROLE_ADMIN]);
        $a = $this->admission(['admit_date' => '2026-06-01', 'admitted_from' => 'ER', 'current_location' => 'Ward']);
        $mrn = $a->patient->mrn;

        $this->actingAs($admin)->post("/admissions/{$a->id}/modify", [
            'mrn' => $mrn, 'name' => 'G4 Patient', 'age' => 40, 'gender' => 'Male', 'bed' => 'W-9',
            'admit_date' => '2026-06-05', 'admitted_from' => 'Referral', 'current_location' => 'ICU',
            'diagnoses' => ['A15.0'],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $a->refresh();
        $this->assertSame('2026-06-05', $a->admit_date->toDateString());
        $this->assertSame('Referral', $a->admitted_from);
        $this->assertSame('ICU', $a->current_location);
        $this->assertSame('W-9', $a->bed);

        $log = AuditLog::where('action', 'patient.modify')->where('entity_id', (string) $a->id)->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame('2026-06-01', $log->details['admit_date_was'] ?? null, 'audit must record the OLD admit date');
    }

    public function test_modify_audit_omits_old_date_when_admit_date_unchanged(): void
    {
        $admin = $this->user(['role' => User::ROLE_ADMIN]);
        $a = $this->admission(['admit_date' => '2026-06-01']);

        $this->actingAs($admin)->post("/admissions/{$a->id}/modify", [
            'mrn' => $a->patient->mrn, 'name' => 'G4 Patient',
            'admit_date' => '2026-06-01', 'current_location' => 'Ward', 'diagnoses' => [],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $log = AuditLog::where('action', 'patient.modify')->where('entity_id', (string) $a->id)->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertArrayNotHasKey('admit_date_was', $log->details ?? []);
    }

    public function test_edit_json_exposes_the_new_modify_fields(): void
    {
        $admin = $this->user(['role' => User::ROLE_ADMIN]);
        $a = $this->admission(['admit_date' => '2026-06-02', 'admitted_from' => 'Clinic', 'current_location' => 'ER']);

        $this->actingAs($admin)->get("/admissions/{$a->id}/edit")
            ->assertOk()
            ->assertJson(['admit_date' => '2026-06-02', 'admitted_from' => 'Clinic', 'current_location' => 'ER']);
    }

    // ---- 3. admin delete admission ------------------------------------------------------------

    public function test_admin_can_delete_admission_with_audit(): void
    {
        $admin = $this->user(['role' => User::ROLE_ADMIN]);
        $a = $this->admission(['admit_date' => '2026-06-03']);
        $a->diagnoses()->create(['seq' => 1, 'icd10_code' => 'A15.0']);
        $mrn = $a->patient->mrn;
        $id = $a->id;

        $this->actingAs($admin)->delete("/admissions/{$id}")->assertRedirect();

        $this->assertDatabaseMissing('admissions', ['id' => $id]);
        $this->assertDatabaseMissing('admission_diagnoses', ['admission_id' => $id]);
        $log = AuditLog::where('action', 'admission.delete')->where('entity_id', (string) $id)->first();
        $this->assertNotNull($log, 'delete must be audited');
        $this->assertSame($mrn, $log->details['mrn'] ?? null);
        $this->assertSame('2026-06-03', $log->details['admit_date'] ?? null);
    }

    public function test_non_admin_cannot_delete_admission(): void
    {
        $cons = $this->user(['can_manage' => 1, 'can_modify' => 1]);   // even a fully-capable consultant
        $a = $this->admission(['consultant_id' => $cons->id]);

        $this->actingAs($cons)->delete("/admissions/{$a->id}")->assertForbidden();
        $this->assertDatabaseHas('admissions', ['id' => $a->id]);
    }

    // ---- 4. D1 — consultants see only their own patients ---------------------------------------

    public function test_consultant_sees_only_own_group_with_scoped_stats(): void
    {
        $me = $this->user(['name' => 'Me Cons']);
        $other = $this->user(['name' => 'Other Cons']);
        $this->admission(['consultant_id' => $me->id]);
        $this->admission(['consultant_id' => $me->id, 'current_location' => 'ICU']);
        $this->admission(['consultant_id' => $other->id]);
        $this->admission();   // unassigned — stays visible in the queue chip

        $this->actingAs($me)->get('/patients')->assertInertia(fn (AssertableInertia $page) => $page
            ->has('groups', 1)
            ->where('groups.0.id', $me->id)
            ->where('stats.total', 2)          // scoped census
            ->where('stats.icu', 1)            // scoped ICU
            ->where('stats.unassigned', 1));   // global — consultants may still grab from the queue
    }

    public function test_registrar_sees_all_groups(): void
    {
        $registrar = $this->user(['role' => User::ROLE_REGISTRAR]);
        $a = $this->user(['name' => 'Cons A']);
        $b = $this->user(['name' => 'Cons B']);
        $this->admission(['consultant_id' => $a->id]);
        $this->admission(['consultant_id' => $b->id]);

        $this->actingAs($registrar)->get('/patients')->assertInertia(fn (AssertableInertia $page) => $page
            ->has('groups', 2)
            ->where('stats.total', 2));
    }
}
