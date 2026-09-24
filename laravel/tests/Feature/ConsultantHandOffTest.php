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
 * Consultant hand-off (owner decision 2026-09-24, role/UX review #13): a CONSULTANT who is the current
 * consultant of an ACTIVE episode may hand that patient to another active consultant through the
 * single assign action, without the Assign capability. Everything else about assign is unchanged:
 * the handover signature, the receiver's notification and the same-day reminder still apply.
 */
class ConsultantHandOffTest extends TestCase
{
    use RefreshDatabase;

    private function user(int $role, array $extra = []): User
    {
        return User::create(array_merge([
            'username' => 'ho_'.$role.'_'.substr(md5(uniqid('', true)), 0, 8),
            'name' => 'HO User '.$role, 'password' => 'secret12345', 'role' => $role, 'active' => 1,
            'on_service' => 1, 'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
        ], $extra));
    }

    private function admission(?int $consultantId, array $extra = []): Admission
    {
        $p = Patient::create(['mrn' => (string) random_int(10000000, 99999999), 'name' => 'HO Patient']);

        return Admission::create(array_merge([
            'patient_id' => $p->id, 'admit_date' => now()->subDays(2)->toDateString(),
            'current_location' => 'Ward', 'is_longterm' => 0, 'is_new_assignment' => 0,
            'consultant_id' => $consultantId,
        ], $extra));
    }

    public function test_a_consultant_hands_their_own_active_patient_to_a_colleague(): void
    {
        $mine = $this->user(User::ROLE_CONSULTANT);
        $colleague = $this->user(User::ROLE_CONSULTANT);
        $a = $this->admission($mine->id);

        $this->actingAs($mine)->post("/admissions/{$a->id}/assign", ['consultant_id' => $colleague->id, 'mark_new' => true])
            ->assertRedirect()
            ->assertSessionHas('flash', fn ($f) => $f['type'] === 'success' && str_contains($f['message'], 'read and sign'));

        $this->assertSame($colleague->id, (int) $a->fresh()->consultant_id);
        // the same safeguards as any consultant-to-consultant move
        $this->assertDatabaseHas('handover_signatures', ['admission_id' => $a->id, 'to_consultant_id' => $colleague->id]);
        $this->assertDatabaseHas('notifications', ['user_id' => $colleague->id, 'type' => 'handover.transfer']);
        $audit = AuditLog::where('action', 'admission.assign')->latest('id')->first();
        $this->assertNotNull($audit);
        $this->assertSame('consultant_hand_off', $audit->details['via'] ?? null);
    }

    public function test_the_same_day_handover_reminder_still_applies_to_a_hand_off(): void
    {
        $mine = $this->user(User::ROLE_CONSULTANT);
        $colleague = $this->user(User::ROLE_CONSULTANT);
        $a = $this->admission($mine->id);   // no handover saved today

        $this->actingAs($mine)->post("/admissions/{$a->id}/assign", ['consultant_id' => $colleague->id])->assertRedirect();

        $this->assertSame($colleague->id, (int) $a->fresh()->consultant_id);   // soft gate: the move proceeds
        $this->assertDatabaseHas('notifications', ['type' => 'handover.incomplete', 'resolved_at' => null]);
    }

    public function test_a_consultant_cannot_hand_off_someone_elses_patient(): void
    {
        $me = $this->user(User::ROLE_CONSULTANT);
        $owner = $this->user(User::ROLE_CONSULTANT);
        $a = $this->admission($owner->id);

        $this->actingAs($me)->post("/admissions/{$a->id}/assign", ['consultant_id' => $me->id])->assertForbidden();
        $this->assertSame($owner->id, (int) $a->fresh()->consultant_id);
    }

    public function test_a_consultant_cannot_take_an_unassigned_patient_through_assign(): void
    {
        $me = $this->user(User::ROLE_CONSULTANT);
        $a = $this->admission(null);   // the queue: Assign-to-me is the route for this, not assign

        $this->actingAs($me)->post("/admissions/{$a->id}/assign", ['consultant_id' => $me->id])->assertForbidden();
        $this->assertNull($a->fresh()->consultant_id);
    }

    public function test_a_hand_off_must_go_to_someone_else(): void
    {
        $mine = $this->user(User::ROLE_CONSULTANT);
        $a = $this->admission($mine->id);

        $this->actingAs($mine)->from('/patients')->post("/admissions/{$a->id}/assign", ['consultant_id' => $mine->id])
            ->assertSessionHasErrors(['consultant_id' => 'This patient is already yours — choose the colleague you are handing them to.']);
        $this->assertDatabaseMissing('handover_signatures', ['admission_id' => $a->id]);
    }

    public function test_a_hand_off_target_must_be_an_active_consultant(): void
    {
        $mine = $this->user(User::ROLE_CONSULTANT);
        $resident = $this->user(User::ROLE_RESIDENT);
        $inactive = $this->user(User::ROLE_CONSULTANT, ['active' => 0]);
        $a = $this->admission($mine->id);

        foreach ([$resident, $inactive] as $target) {
            $this->actingAs($mine)->from('/patients')->post("/admissions/{$a->id}/assign", ['consultant_id' => $target->id])
                ->assertSessionHasErrors('consultant_id');
        }
        $this->assertSame($mine->id, (int) $a->fresh()->consultant_id);
    }

    public function test_a_closed_episode_cannot_be_handed_off(): void
    {
        $mine = $this->user(User::ROLE_CONSULTANT);
        $colleague = $this->user(User::ROLE_CONSULTANT);
        $a = $this->admission($mine->id, [
            'discharge_date' => now()->toDateString(), 'medical_discharge_date' => now()->toDateString(), 'outcome' => 'Alive',
        ]);

        $this->actingAs($mine)->post("/admissions/{$a->id}/assign", ['consultant_id' => $colleague->id])->assertForbidden();
        $this->assertSame($mine->id, (int) $a->fresh()->consultant_id);
    }

    public function test_only_consultants_get_the_hand_off_not_other_self_assigned_roles(): void
    {
        // A Registrar or Resident who self-assigned is the "primary" for canManageAdmission, but the owner
        // granted the hand-off to consultants only.
        $colleague = $this->user(User::ROLE_CONSULTANT);
        foreach ([User::ROLE_RESIDENT, User::ROLE_REGISTRAR] as $role) {
            $me = $this->user($role);
            $a = $this->admission($me->id);
            $this->actingAs($me)->post("/admissions/{$a->id}/assign", ['consultant_id' => $colleague->id])->assertForbidden();
            $this->assertSame($me->id, (int) $a->fresh()->consultant_id);
        }
    }

    public function test_observers_and_deactivated_consultants_get_nothing(): void
    {
        $colleague = $this->user(User::ROLE_CONSULTANT);
        $observer = $this->user(User::ROLE_OBSERVER);
        $a = $this->admission($observer->id);   // pathological row: an observer can never be a consultant
        $this->actingAs($observer)->post("/admissions/{$a->id}/assign", ['consultant_id' => $colleague->id])->assertForbidden();

        $gone = $this->user(User::ROLE_CONSULTANT, ['active' => 0]);
        $b = $this->admission($gone->id);
        $this->assertFalse($gone->canHandOffAdmission($b));
    }

    public function test_the_assign_capability_path_is_unchanged(): void
    {
        // a consultant WITH can_assign keeps the old behaviour: may assign any patient, to anyone active
        $assigner = $this->user(User::ROLE_CONSULTANT, ['can_assign' => 1]);
        $owner = $this->user(User::ROLE_CONSULTANT);
        $to = $this->user(User::ROLE_CONSULTANT);
        $a = $this->admission($owner->id);

        $this->actingAs($assigner)->post("/admissions/{$a->id}/assign", ['consultant_id' => $to->id])
            ->assertRedirect()->assertSessionHas('flash', fn ($f) => $f['message'] === 'Consultant assigned.');
        $this->assertSame($to->id, (int) $a->fresh()->consultant_id);
        $audit = AuditLog::where('action', 'admission.assign')->latest('id')->first();
        $this->assertArrayNotHasKey('via', $audit->details);
    }
}
