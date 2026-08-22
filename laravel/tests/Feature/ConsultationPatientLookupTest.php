<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\Consultation;
use App\Models\ConsultationReason;
use App\Models\Patient;
use App\Models\Specialty;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Wave 2b — patient lookup on create.
 *
 * An MRN that matches no patient record used to store patient_id = NULL in silence. It now WARNS:
 * the user either picks the patient from POST /api/patients/quick-search (patient_id +
 * admission_id ride the body) or explicitly acknowledges the unmatched MRN. EDIT keeps the legacy
 * behaviour so historical rows stay editable. entered_by remains session-sourced and unspoofable.
 */
class ConsultationPatientLookupTest extends TestCase
{
    use RefreshDatabase;

    private function user(int $role = User::ROLE_CONSULTANT, array $extra = []): User
    {
        return User::create(array_merge([
            'username' => 'cpl_' . substr(md5(uniqid('', true)), 0, 10),
            'name' => 'Lookup User', 'password' => 'secret12345', 'role' => $role, 'active' => 1,
            'email_verified_at' => now(),
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
        ], $extra));
    }

    /**
     * The registrar who files the consultation. She carries the coordinator capability for the same
     * reason ResidualR3Test's does: the W1 own-specialty rule refuses a specialty-less account, and
     * these cases are about the PATIENT LOOKUP, not about specialty scoping (ConsultationScopingTest
     * owns that).
     */
    private function booker(): User
    {
        return $this->user(User::ROLE_REGISTRAR, ['can_coordinate_consultations' => true]);
    }

    private function payload(array $overrides = []): array
    {
        $cardio = Specialty::firstOrCreate(['name' => 'Cardiology'], ['is_subspecialty' => true, 'is_external' => false]);
        $reason = ConsultationReason::firstOrCreate(['name' => 'Lookup Reason']);
        $consultant = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id, 'on_service' => 1]);

        return array_merge([
            'mrn' => '70001111', 'patient_name' => 'Lookup Pt', 'age' => 52, 'bed' => 'W-4',
            'current_location' => 'Ward', 'consultation_date' => now()->toDateString(),
            'consultation_from' => 'ER', 'to_service' => 'Cardiology',
            'consultant_id' => $consultant->id, 'indication' => [$reason->id],
        ], $overrides);
    }

    public function test_an_unmatched_mrn_is_refused_with_a_warning_instead_of_creating_an_orphan(): void
    {
        $this->actingAs($this->booker())->from('/consultations')
            ->post('/consultations', $this->payload())
            ->assertRedirect('/consultations')
            ->assertSessionHasErrors('mrn');

        $this->assertSame(0, Consultation::count(), 'an unmatched MRN must not silently create an orphan row');
    }

    public function test_acknowledging_the_warning_files_the_consultation_unlinked(): void
    {
        $this->actingAs($this->booker())
            ->post('/consultations', $this->payload(['unmatched_mrn_ack' => true]))
            ->assertRedirect()->assertSessionHasNoErrors();

        $c = Consultation::firstOrFail();
        $this->assertSame('70001111', (string) $c->mrn);
        $this->assertNull($c->patient_id);
        $this->assertNull($c->admission_id);
    }

    public function test_a_matching_mrn_links_the_patient_with_no_acknowledgement_needed(): void
    {
        $p = Patient::create(['mrn' => '70001111', 'name' => 'Lookup Pt', 'age' => 52]);

        $this->actingAs($this->booker())
            ->post('/consultations', $this->payload())
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame($p->id, (int) Consultation::firstOrFail()->patient_id);
    }

    public function test_picking_a_patient_links_both_the_patient_and_the_admission(): void
    {
        $p = Patient::create(['mrn' => '70002222', 'name' => 'Picked Pt', 'age' => 61]);
        $a = Admission::create([
            'patient_id' => $p->id, 'admit_date' => now()->subDays(2)->toDateString(),
            'current_location' => 'Ward', 'bed' => 'W-9', 'is_longterm' => 0, 'is_new_assignment' => 0,
        ]);

        $this->actingAs($this->booker())
            ->post('/consultations', $this->payload([
                'mrn' => '70002222', 'patient_id' => $p->id, 'admission_id' => $a->id,
            ]))
            ->assertRedirect()->assertSessionHasNoErrors();

        $c = Consultation::firstOrFail();
        $this->assertSame($p->id, (int) $c->patient_id);
        $this->assertSame($a->id, (int) $c->admission_id);
    }

    public function test_an_admission_belonging_to_someone_else_is_ignored(): void
    {
        $mine = Patient::create(['mrn' => '70003333', 'name' => 'Mine', 'age' => 40]);
        $other = Patient::create(['mrn' => '70004444', 'name' => 'Other', 'age' => 40]);
        $otherAdmission = Admission::create([
            'patient_id' => $other->id, 'admit_date' => now()->subDay()->toDateString(),
            'current_location' => 'Ward', 'is_longterm' => 0, 'is_new_assignment' => 0,
        ]);

        $this->actingAs($this->booker())
            ->post('/consultations', $this->payload([
                'mrn' => '70003333', 'patient_id' => $mine->id, 'admission_id' => $otherAdmission->id,
            ]))
            ->assertRedirect()->assertSessionHasNoErrors();

        $c = Consultation::firstOrFail();
        $this->assertSame($mine->id, (int) $c->patient_id);
        $this->assertNull($c->admission_id, 'an admission that is not this patient\'s must never be attached');
    }

    public function test_entered_by_is_session_sourced_and_cannot_be_set_from_the_payload(): void
    {
        Patient::create(['mrn' => '70001111', 'name' => 'Lookup Pt', 'age' => 52]);
        $actor = $this->booker();
        $victim = $this->user(User::ROLE_CONSULTANT);

        $this->actingAs($actor)
            ->post('/consultations', $this->payload(['entered_by' => $victim->id]))
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame($actor->id, (int) Consultation::firstOrFail()->entered_by);
    }

    public function test_a_picked_patient_whose_mrn_is_not_the_typed_mrn_is_refused(): void
    {
        Patient::create(['mrn' => '70001111', 'name' => 'Right Pt', 'age' => 52]);
        $wrong = Patient::create(['mrn' => '70005555', 'name' => 'Wrong Pt', 'age' => 52]);

        // the MRN the clinician reads on the ledger and the patient the row JOINS to must be the
        // same person — a picked patient may not silently override the typed MRN
        $this->actingAs($this->booker())->from('/consultations')
            ->post('/consultations', $this->payload(['patient_id' => $wrong->id]))
            ->assertRedirect('/consultations')
            ->assertSessionHasErrors('mrn');

        $this->assertSame(0, Consultation::count());
    }

    public function test_a_soft_deleted_patient_or_admission_can_never_be_picked(): void
    {
        $trashed = Patient::create(['mrn' => '70006666', 'name' => 'Trashed Pt', 'age' => 44]);
        $trashed->delete();

        // exists:patients,id is soft-delete-blind; a trashed pick used to pass validation and then
        // resolve to NULL in the controller — the exact silent orphan this task exists to prevent
        $this->actingAs($this->booker())->from('/consultations')
            ->post('/consultations', $this->payload(['mrn' => '70006666', 'patient_id' => $trashed->id]))
            ->assertRedirect('/consultations')
            ->assertSessionHasErrors('patient_id');

        $live = Patient::create(['mrn' => '70007777', 'name' => 'Live Pt', 'age' => 44]);
        $trashedAdmission = Admission::create([
            'patient_id' => $live->id, 'admit_date' => now()->subDay()->toDateString(),
            'current_location' => 'Ward', 'is_longterm' => 0, 'is_new_assignment' => 0,
        ]);
        $trashedAdmission->delete();

        $this->actingAs($this->booker())->from('/consultations')
            ->post('/consultations', $this->payload([
                'mrn' => '70007777', 'patient_id' => $live->id, 'admission_id' => $trashedAdmission->id,
            ]))
            ->assertRedirect('/consultations')
            ->assertSessionHasErrors('admission_id');

        $this->assertSame(0, Consultation::count());
    }

    public function test_an_unknown_patient_id_is_refused(): void
    {
        $this->actingAs($this->booker())->from('/consultations')
            ->post('/consultations', $this->payload(['patient_id' => 987654]))
            ->assertRedirect('/consultations')
            ->assertSessionHasErrors('patient_id');

        $this->assertSame(0, Consultation::count());
    }

    public function test_an_edit_can_never_relink_the_patient_or_the_admission(): void
    {
        $cardio = Specialty::firstOrCreate(['name' => 'Cardiology'], ['is_subspecialty' => true, 'is_external' => false]);
        $right = Patient::create(['mrn' => '70008888', 'name' => 'Right Pt', 'age' => 52]);
        $wrong = Patient::create(['mrn' => '70009999', 'name' => 'Wrong Pt', 'age' => 52]);
        $wrongAdmission = Admission::create([
            'patient_id' => $wrong->id, 'admit_date' => now()->subDay()->toDateString(),
            'current_location' => 'Ward', 'is_longterm' => 0, 'is_new_assignment' => 0,
        ]);
        $admin = $this->user(User::ROLE_ADMIN);
        $c = Consultation::create([
            'mrn' => '70008888', 'patient_name' => 'Right Pt', 'age' => 52, 'bed' => 'W-2',
            'current_location' => 'Ward', 'consultation_date' => now()->toDateString(),
            'consultation_from' => 'ER', 'to_service' => 'Cardiology', 'consultant_id' => $admin->id,
            'indication' => [1], 'owning_specialty_id' => $cardio->id, 'patient_id' => $right->id,
        ]);

        // the lookup fields are CREATE-only. Re-pointing a clinical record at another patient is not
        // an edit, and mass assignment would do it with nothing in the audit diff.
        $this->actingAs($admin)->put("/consultations/{$c->id}", [
            'mrn' => '70008888', 'patient_name' => 'Right Pt', 'age' => 52, 'bed' => 'W-2',
            'current_location' => 'Ward', 'consultation_date' => now()->toDateString(),
            'consultation_from' => 'ER', 'to_service' => 'Cardiology', 'consultant_id' => $admin->id,
            'indication' => [1], 'patient_id' => $wrong->id, 'admission_id' => $wrongAdmission->id,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $c->refresh();
        $this->assertSame($right->id, (int) $c->patient_id, 'an edit must never relink the patient');
        $this->assertNull($c->admission_id, 'an edit must never attach an admission');
    }

    public function test_editing_a_legacy_consultation_with_an_unmatched_mrn_still_saves(): void
    {
        $cardio = Specialty::firstOrCreate(['name' => 'Cardiology'], ['is_subspecialty' => true, 'is_external' => false]);
        $admin = $this->user(User::ROLE_ADMIN);
        $c = Consultation::create([
            'mrn' => '99999999', 'patient_name' => 'Legacy Pt', 'age' => 70, 'bed' => 'W-2',
            'current_location' => 'Ward', 'consultation_date' => '2019-05-01', 'consultation_from' => 'ER',
            'to_service' => 'Cardiology', 'consultant_id' => $admin->id, 'indication' => [1],
            'owning_specialty_id' => $cardio->id,
        ]);

        $this->actingAs($admin)->put("/consultations/{$c->id}", [
            'mrn' => '99999999', 'patient_name' => 'Legacy Pt Edited', 'age' => 70, 'bed' => 'W-2',
            'current_location' => 'Ward', 'consultation_date' => '2019-05-01', 'consultation_from' => 'ER',
            'to_service' => 'Cardiology', 'consultant_id' => $admin->id, 'indication' => [1],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('Legacy Pt Edited', $c->fresh()->patient_name);
    }
}
