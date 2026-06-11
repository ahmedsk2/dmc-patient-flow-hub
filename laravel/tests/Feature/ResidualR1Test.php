<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\AuditLog;
use App\Models\Consultation;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Residual batch R1 — clinical data-quality:
 *  1. one-step discharge (medical-discharge with complete=true closes the file in one request)
 *  2. controlled destination vocabulary (+ Dead ⇒ Mortuary forced server-side) and delay-reason vocab
 *  3. duplicate active-MRN guard on new admissions (legacy parity)
 *  4. reverse-signoff limited to the 48h window (mirrors reverse-discharge)
 *  5. the edit JSON (Modify-modal feed) is authorization-gated
 */
class ResidualR1Test extends TestCase
{
    use RefreshDatabase;

    private function user(int $role, array $extra = []): User
    {
        return User::create(array_merge([
            'username' => 'r1_' . substr(md5(uniqid('', true)), 0, 10),
            'name' => 'R1 User', 'password' => 'secret12345', 'role' => $role, 'active' => 1,
        ], $extra));
    }

    private function admin(): User
    {
        return $this->user(User::ROLE_ADMIN);
    }

    private function admission(array $overrides = []): Admission
    {
        $p = Patient::create(['mrn' => (string) random_int(10000000, 99999999), 'name' => 'R1 Patient']);

        return Admission::create(array_merge([
            'patient_id' => $p->id, 'admit_date' => now()->subDays(3)->toDateString(),
            'current_location' => 'Ward', 'is_longterm' => 0, 'is_new_assignment' => 0,
        ], $overrides));
    }

    // ---- 1. one-step discharge -------------------------------------------------------------

    public function test_one_step_discharge_closes_file_in_one_request(): void
    {
        $a = $this->admission();
        $date = now()->subDay()->toDateString();
        $this->actingAs($this->admin())->post("/admissions/{$a->id}/medical-discharge", [
            'outcome' => 'Alive', 'medical_discharge_date' => $date,
            'discharge_to' => 'Home', 'complete' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $a->refresh();
        $this->assertSame($date, $a->medical_discharge_date->toDateString());
        $this->assertNotNull($a->discharge_date, 'complete=true must close the file in the same request');
        $this->assertSame($date, $a->discharge_date->toDateString(), 'discharge_date mirrors the medical discharge date');
        $this->assertSame('discharge from ward', $a->transfer_type);
        $this->assertSame('Home', $a->discharge_to);
        $this->assertTrue(AuditLog::where('action', 'admission.discharge_both')->where('entity_id', (string) $a->id)->exists());
    }

    public function test_one_step_discharge_from_icu_sets_icu_transfer_type(): void
    {
        $a = $this->admission(['current_location' => 'ICU']);
        $this->actingAs($this->admin())->post("/admissions/{$a->id}/medical-discharge", [
            'outcome' => 'Alive', 'medical_discharge_date' => now()->toDateString(),
            'discharge_to' => 'Home', 'complete' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('discharge from ICU', $a->fresh()->transfer_type);
    }

    public function test_medical_only_discharge_still_leaves_file_open(): void
    {
        $a = $this->admission();
        $this->actingAs($this->admin())->post("/admissions/{$a->id}/medical-discharge", [
            'outcome' => 'Alive', 'medical_discharge_date' => now()->toDateString(),
            'discharge_to' => 'Home', 'delay_reason' => 'Physical',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $a->refresh();
        $this->assertNotNull($a->medical_discharge_date);
        $this->assertNull($a->discharge_date, 'medical-only discharge must not close the file');
        $this->assertNull($a->transfer_type);
        $this->assertSame('Physical', $a->delay_reason);
        $this->assertTrue(AuditLog::where('action', 'admission.medical_discharge')->where('entity_id', (string) $a->id)->exists());
    }

    // ---- 2. controlled destination vocabulary ----------------------------------------------

    public function test_dead_outcome_forces_mortuary_on_medical_discharge(): void
    {
        // H1 revert: medical-only locks the outcome to Alive (legacy phase-1), so a death is
        // recorded on the COMPLETE step — here via the one-step close (complete=true)
        $a = $this->admission();
        $this->actingAs($this->admin())->post("/admissions/{$a->id}/medical-discharge", [
            'outcome' => 'Dead', 'medical_discharge_date' => now()->toDateString(),
            'discharge_to' => 'Home',   // tampered client value — server must override
            'complete' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('Mortuary', $a->fresh()->discharge_to);
    }

    public function test_dead_outcome_forces_mortuary_on_icu_discharge(): void
    {
        $a = $this->admission(['current_location' => 'ICU']);
        $this->actingAs($this->admin())->post("/admissions/{$a->id}/icu-discharge", [
            'outcome' => 'Dead', 'discharge_date' => now()->toDateString(),
            'discharge_to' => 'Home',   // tampered client value — server must override
        ])->assertRedirect()->assertSessionHasNoErrors();

        $a->refresh();
        $this->assertSame('Mortuary', $a->discharge_to);
        $this->assertNotNull($a->discharge_date);
    }

    public function test_invalid_destination_and_delay_reason_are_rejected(): void
    {
        $a = $this->admission();
        $this->actingAs($this->admin())->from('/patients')->post("/admissions/{$a->id}/medical-discharge", [
            'outcome' => 'Alive', 'medical_discharge_date' => now()->toDateString(),
            'discharge_to' => 'Narnia', 'delay_reason' => 'Vacation',
        ])->assertRedirect('/patients')->assertSessionHasErrors(['discharge_to', 'delay_reason']);

        $this->assertNull($a->fresh()->medical_discharge_date, 'rejected payload must not mutate the admission');

        $icu = $this->admission(['current_location' => 'ICU']);
        $this->actingAs($this->admin())->from('/patients')->post("/admissions/{$icu->id}/icu-discharge", [
            'outcome' => 'Alive', 'discharge_date' => now()->toDateString(),
            'discharge_to' => 'Narnia',
        ])->assertRedirect('/patients')->assertSessionHasErrors('discharge_to');

        $this->assertNull($icu->fresh()->discharge_date);
    }

    public function test_icu_discharge_persists_valid_destination(): void
    {
        $a = $this->admission(['current_location' => 'ICU']);
        $this->actingAs($this->admin())->post("/admissions/{$a->id}/icu-discharge", [
            'outcome' => 'Alive', 'discharge_date' => now()->toDateString(),
            'discharge_to' => 'Other Facility',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('Other Facility', $a->fresh()->discharge_to);
    }

    // ---- 3. duplicate active-MRN guard ------------------------------------------------------

    public function test_second_admission_of_an_active_mrn_is_rejected(): void
    {
        $p = Patient::create(['mrn' => '55667788', 'name' => 'Dup Test']);
        Admission::create(['patient_id' => $p->id, 'admit_date' => now()->subDay()->toDateString(), 'current_location' => 'Ward']);

        $this->actingAs($this->admin())->from('/admissions/create')->post('/admissions', [
            'mrn' => '55667788', 'name' => 'Dup Test',
            'admit_date' => now()->toDateString(), 'current_location' => 'Ward',
        ])->assertRedirect('/admissions/create')->assertSessionHasErrors('mrn');

        $this->assertSame(1, Admission::where('patient_id', $p->id)->count(), 'duplicate active admission must not be created');
    }

    public function test_admission_after_discharge_succeeds_for_the_same_mrn(): void
    {
        $p = Patient::create(['mrn' => '55667799', 'name' => 'Readmit Test']);
        Admission::create(['patient_id' => $p->id, 'admit_date' => now()->subDays(9)->toDateString(),
            'discharge_date' => now()->subDays(5)->toDateString(), 'current_location' => 'Ward',
            'outcome' => 'Alive', 'transfer_type' => 'discharge from ward']);

        \App\Models\Country::firstOrCreate(['name' => 'Saudi Arabia'], ['code' => 'SA']);
        $this->actingAs($this->admin())->post('/admissions', [
            'mrn' => '55667799', 'name' => 'Readmit Test', 'age' => 50, 'gender' => 'Male',
            'nationality' => 'Saudi Arabia', 'bed' => 'W-1', 'diagnoses' => ['J18.9'],   // B1 Fill-All
            'admit_date' => now()->toDateString(), 'current_location' => 'Ward',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(2, Admission::where('patient_id', $p->id)->count(), 'readmission after discharge must be allowed');
    }

    // ---- 4. reverse-signoff same-day guard (H2/B4: was 48h, tightened to legacy same-day) ------

    public function test_reverse_signoff_blocked_when_not_same_day(): void
    {
        $old = Consultation::create(['mrn' => '1001', 'patient_name' => 'Old Signoff',
            'consultation_date' => now()->subDays(12)->toDateString(), 'signoff_date' => now()->subDays(10)->toDateString()]);
        $this->actingAs($this->admin())->post("/consultations/{$old->id}/reverse-signoff")->assertRedirect();
        $this->assertNotNull($old->fresh()->signoff_date, 'old sign-offs must not be silently reopened');

        $recent = Consultation::create(['mrn' => '1002', 'patient_name' => 'Recent Signoff',
            'consultation_date' => now()->subDay()->toDateString(), 'signoff_date' => now()->toDateString()]);
        $this->actingAs($this->admin())->post("/consultations/{$recent->id}/reverse-signoff")->assertRedirect();
        $this->assertNull($recent->fresh()->signoff_date, 'same-day sign-offs remain reversible');
    }

    // ---- 5. edit JSON gate --------------------------------------------------------------------

    public function test_edit_json_is_gated(): void
    {
        $a = $this->admission();

        $this->actingAs($this->user(User::ROLE_OBSERVER))->get("/admissions/{$a->id}/edit")->assertForbidden();
        $this->actingAs($this->user(User::ROLE_RESIDENT)) // no capability, not the owner
            ->get("/admissions/{$a->id}/edit")->assertForbidden();

        $this->actingAs($this->user(User::ROLE_RESIDENT, ['can_modify' => true]))
            ->get("/admissions/{$a->id}/edit")->assertOk()->assertJsonPath('id', $a->id);
        $this->actingAs($this->user(User::ROLE_REGISTRAR, ['can_manage' => true]))
            ->get("/admissions/{$a->id}/edit")->assertOk();
        $this->actingAs($this->admin())->get("/admissions/{$a->id}/edit")->assertOk();

        $owner = $this->user(User::ROLE_CONSULTANT);
        $owned = $this->admission(['consultant_id' => $owner->id]);
        $this->actingAs($owner)->get("/admissions/{$owned->id}/edit")->assertOk();
        $this->actingAs($this->user(User::ROLE_CONSULTANT))   // a different consultant, no capability
            ->get("/admissions/{$owned->id}/edit")->assertForbidden();
    }
}
