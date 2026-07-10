<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\Consultation;
use App\Models\ConsultationReason;
use App\Models\Handover;
use App\Models\HandoverSignature;
use App\Models\Patient;
use App\Models\Specialty;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Round-12 O1: the 5 genuine discovery findings — icuPull signature voiding, consultation
 * age/date validate-on-change, addSpecialty/addReason coverage, edit-JSON Observer short-circuit,
 * and the import duplicate-active-MRN guard.
 */
class Round12O1Test extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['username' => 'o1_'.substr(md5(uniqid('', true)), 0, 8), 'name' => 'O1 Admin',
            'password' => 'secret12345', 'role' => User::ROLE_ADMIN, 'active' => 1,
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now()]);
    }

    private function consultant(string $name = 'O1 Cons'): User
    {
        return User::create(['username' => 'o1c_'.substr(md5(uniqid('', true)), 0, 8), 'name' => $name,
            'password' => 'secret12345', 'role' => User::ROLE_CONSULTANT, 'active' => 1,
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now()]);
    }

    private function admission(array $o = []): Admission
    {
        $p = Patient::create(['mrn' => (string) random_int(10000000, 99999999), 'name' => 'O1 Patient']);

        return Admission::create(array_merge([
            'patient_id' => $p->id, 'admit_date' => now()->subDays(2)->toDateString(),
            'current_location' => 'ICU', 'is_longterm' => 0, 'is_new_assignment' => 0,
        ], $o));
    }

    /* ---- 1. icuPull voids the pending handover signature on the closed ICU episode ---- */
    public function test_icu_pull_voids_pending_handover_signature(): void
    {
        $from = $this->consultant('From ICU'); $to = $this->consultant('To Ward');
        $a = $this->admission(['consultant_id' => $from->id, 'current_location' => 'ICU']);
        Handover::create(['admission_id' => $a->id, 'body' => 'h', 'updated_by' => $from->id]);
        $sig = HandoverSignature::create(['admission_id' => $a->id, 'from_consultant_id' => $from->id,
            'to_consultant_id' => $to->id, 'required_at' => now()]);

        $this->actingAs($this->admin())->post("/admissions/{$a->id}/icu-pull")->assertRedirect();

        $this->assertNotNull($sig->fresh()->voided_at, 'icuPull must void the pending signature (no orphan in the inbox)');
    }

    /* ---- 2. consultation age/date validate-on-change (imported rows stay editable) ---- */
    public function test_consultation_with_null_age_and_old_date_is_editable(): void
    {
        $admin = $this->admin();
        $c = Consultation::create(['mrn' => '5550001', 'patient_name' => 'Imported', 'age' => null,
            'consultation_date' => '2019-05-01', 'consultation_from' => 'ER', 'to_service' => 'Cardiology',
            'consultant_id' => $admin->id, 'indication' => [1], 'current_location' => 'Ward']);

        // edit an unrelated field; null age + pre-2000 untouched date must not block the save
        $this->actingAs($admin)->put("/consultations/{$c->id}", [
            'mrn' => '5550001', 'patient_name' => 'Imported Edited', 'age' => null, 'bed' => 'C-1',
            'consultation_date' => '2019-05-01', 'consultation_from' => 'ER', 'to_service' => 'Cardiology',
            'consultant_id' => $admin->id, 'indication' => [1],
        ])->assertRedirect();
        $this->assertSame('Imported Edited', $c->fresh()->patient_name);
    }

    public function test_new_consultation_still_rejects_future_date(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/consultations', [
            'mrn' => '5550002', 'patient_name' => 'Future', 'age' => 40, 'bed' => 'C-2',
            'consultation_date' => now()->addDays(3)->toDateString(), 'consultation_from' => 'ER',
            'to_service' => 'Cardiology', 'consultant_id' => $admin->id, 'indication' => [1],
        ])->assertSessionHasErrors('consultation_date');
    }

    /* ---- 3. addSpecialty / addReason coverage ---- */
    public function test_admin_can_add_specialty_and_reason_non_admin_cannot(): void
    {
        $this->actingAs($this->admin())->post('/control/specialties', ['name' => 'Nephrology', 'is_external' => true])->assertRedirect();
        $this->assertDatabaseHas('specialties', ['name' => 'Nephrology', 'is_external' => true]);

        $this->actingAs($this->admin())->post('/control/reasons', ['name' => 'Sepsis review'])->assertRedirect();
        $this->assertDatabaseHas('consultation_reasons', ['name' => 'Sepsis review']);

        $this->actingAs($this->admin())->post('/control/specialties', ['name' => ''])->assertSessionHasErrors('name');

        $consultant = $this->consultant();
        $this->actingAs($consultant)->post('/control/specialties', ['name' => 'X'])->assertForbidden();
        $this->actingAs($consultant)->post('/control/reasons', ['name' => 'Y'])->assertForbidden();
    }

    /* ---- 4. edit JSON denies an Observer carrying can_modify ---- */
    public function test_edit_json_denies_observer_with_can_modify(): void
    {
        $obs = User::create(['username' => 'o1_obs', 'name' => 'Obs', 'password' => 'secret12345',
            'role' => User::ROLE_OBSERVER, 'active' => 1, 'can_modify' => 1,
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now()]);
        $a = $this->admission(['current_location' => 'Ward']);
        $this->actingAs($obs)->get("/admissions/{$a->id}/edit")->assertForbidden();

        $modifier = User::create(['username' => 'o1_mod', 'name' => 'Mod', 'password' => 'secret12345',
            'role' => User::ROLE_RESIDENT, 'active' => 1, 'can_modify' => 1,
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now()]);
        $this->actingAs($modifier)->get("/admissions/{$a->id}/edit")->assertOk();
    }

    /* ---- 5. import rejects a 2nd active row for an MRN with an open episode ---- */
    public function test_import_flags_duplicate_active_mrn(): void
    {
        $p = Patient::create(['mrn' => '5559001', 'name' => 'Already In']);
        Admission::create(['patient_id' => $p->id, 'admit_date' => '2024-01-01', 'current_location' => 'Ward',
            'is_longterm' => 0, 'is_new_assignment' => 0]);   // open episode (no discharge_date)

        // a second ACTIVE import row (no discharge date) for the same MRN must be flagged invalid
        $this->actingAs($this->admin())
            ->post('/import/preview', ['rows' => "5559001,Already In,40,M,Saudi,2024-02-01,,Alive,Ward"])
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('Import/Index', false)->where('preview.invalid', 1));
    }
}
