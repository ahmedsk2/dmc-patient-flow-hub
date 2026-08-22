<?php

namespace Tests\Feature;

use App\Models\Consultation;
use App\Models\ConsultationReason;
use App\Models\Notification;
use App\Models\Patient;
use App\Models\Specialty;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Wave 2b — coordinator notification (§6.4).
 * A coordinator booking a consult into a team's book is the ONE case where the owning consultant
 * does not already know, so it raises exactly one `consultation.assigned` bell entry. Self-entered
 * consults and consults entered by non-coordinators raise none — no noise.
 */
class ConsultationCoordinatorNotifyTest extends TestCase
{
    use RefreshDatabase;

    private function user(int $role = User::ROLE_CONSULTANT, array $extra = []): User
    {
        return User::create(array_merge([
            'username' => 'ccn_' . substr(md5(uniqid('', true)), 0, 10),
            'name' => 'Notify User', 'full_name' => 'Dr Notify User',
            'password' => 'secret12345', 'role' => $role, 'active' => 1,
            'email_verified_at' => now(),
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
        ], $extra));
    }

    private function cardiology(): Specialty
    {
        return Specialty::firstOrCreate(['name' => 'Cardiology'], ['is_subspecialty' => true, 'is_external' => false]);
    }

    private function payload(User $owner, array $overrides = []): array
    {
        $reason = ConsultationReason::firstOrCreate(['name' => 'Notify Reason']);
        Patient::firstOrCreate(['mrn' => '71000001'], ['name' => 'Notify Pt', 'age' => 58]);

        return array_merge([
            'mrn' => '71000001', 'patient_name' => 'Notify Pt', 'age' => 58, 'bed' => 'W-6',
            'current_location' => 'Ward', 'consultation_date' => now()->toDateString(),
            'consultation_from' => 'ER', 'to_service' => 'Cardiology',
            'owning_specialty_id' => $this->cardiology()->id,
            'consultant_id' => $owner->id, 'indication' => [$reason->id],
        ], $overrides);
    }

    public function test_a_coordinator_created_consult_notifies_the_owning_consultant_exactly_once(): void
    {
        $cardio = $this->cardiology();
        $owner = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id, 'on_service' => 1, 'full_name' => 'Dr Owner']);
        $coord = $this->user(User::ROLE_REGISTRAR, ['can_coordinate_consultations' => 1, 'full_name' => 'Dr Coordinator']);

        $this->actingAs($coord)->post('/consultations', $this->payload($owner))
            ->assertRedirect()->assertSessionHasNoErrors();

        $n = Notification::where('user_id', $owner->id)->where('type', 'consultation.assigned')->get();
        $this->assertCount(1, $n);
        $this->assertSame('Notify Pt', $n[0]->payload['patient_name']);
        $this->assertSame('Dr Coordinator', $n[0]->payload['by_name']);
        $this->assertSame('created', $n[0]->payload['event']);
        $this->assertSame(Consultation::firstOrFail()->id, (int) $n[0]->payload['consultation_id']);
        $this->assertDatabaseHas('audit_log', ['action' => 'consultation.assign']);
    }

    public function test_a_self_entered_consult_notifies_nobody(): void
    {
        $cardio = $this->cardiology();
        $coord = $this->user(User::ROLE_CONSULTANT, [
            'specialty_id' => $cardio->id, 'on_service' => 1, 'can_coordinate_consultations' => 1,
        ]);

        $this->actingAs($coord)->post('/consultations', $this->payload($coord))
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(0, Notification::where('type', 'consultation.assigned')->count());
    }

    public function test_a_non_coordinator_entering_for_a_colleague_notifies_nobody(): void
    {
        $cardio = $this->cardiology();
        $owner = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id, 'on_service' => 1]);
        $plain = $this->user(User::ROLE_RESIDENT, ['specialty_id' => $cardio->id]);

        $this->actingAs($plain)->post('/consultations', $this->payload($owner))
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(0, Notification::where('type', 'consultation.assigned')->count());
    }

    public function test_reassigning_to_a_new_consultant_notifies_the_new_owner_only(): void
    {
        $cardio = $this->cardiology();
        $first = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id, 'on_service' => 1]);
        $second = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id, 'on_service' => 1]);
        $coord = $this->user(User::ROLE_REGISTRAR, ['can_coordinate_consultations' => 1]);

        $c = Consultation::create([
            'mrn' => '71000002', 'patient_name' => 'Reassign Pt', 'age' => 49, 'bed' => 'W-7',
            'current_location' => 'Ward', 'consultation_date' => now()->toDateString(),
            'consultation_from' => 'ER', 'to_service' => 'Cardiology', 'indication' => [1],
            'owning_specialty_id' => $cardio->id, 'consultant_id' => $first->id,
        ]);

        $this->actingAs($coord)->put("/consultations/{$c->id}", [
            'mrn' => '71000002', 'patient_name' => 'Reassign Pt', 'age' => 49, 'bed' => 'W-7',
            'current_location' => 'Ward', 'consultation_date' => now()->toDateString(),
            'consultation_from' => 'ER', 'to_service' => 'Cardiology', 'indication' => [1],
            'consultant_id' => $second->id,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(1, Notification::where('user_id', $second->id)->where('type', 'consultation.assigned')->count());
        $this->assertSame(0, Notification::where('user_id', $first->id)->where('type', 'consultation.assigned')->count());
        $this->assertSame('reassigned', Notification::where('user_id', $second->id)->firstOrFail()->payload['event']);
    }

    public function test_an_edit_that_does_not_change_the_consultant_raises_no_notification(): void
    {
        $cardio = $this->cardiology();
        $owner = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id, 'on_service' => 1]);
        $coord = $this->user(User::ROLE_REGISTRAR, ['can_coordinate_consultations' => 1]);

        $c = Consultation::create([
            'mrn' => '71000003', 'patient_name' => 'Stable Pt', 'age' => 66, 'bed' => 'W-8',
            'current_location' => 'Ward', 'consultation_date' => now()->toDateString(),
            'consultation_from' => 'ER', 'to_service' => 'Cardiology', 'indication' => [1],
            'owning_specialty_id' => $cardio->id, 'consultant_id' => $owner->id,
        ]);

        $this->actingAs($coord)->put("/consultations/{$c->id}", [
            'mrn' => '71000003', 'patient_name' => 'Stable Pt', 'age' => 66, 'bed' => 'W-88',
            'current_location' => 'Ward', 'consultation_date' => now()->toDateString(),
            'consultation_from' => 'ER', 'to_service' => 'Cardiology', 'indication' => [1],
            'consultant_id' => $owner->id,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(0, Notification::where('type', 'consultation.assigned')->count());
        $this->assertSame('W-88', $c->fresh()->bed);
    }

    public function test_an_observer_never_reaches_the_notification_path(): void
    {
        $cardio = $this->cardiology();
        $owner = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id, 'on_service' => 1]);
        // capability flags set ON PURPOSE: read-only wins over every one of them
        $obs = $this->user(User::ROLE_OBSERVER, ['can_coordinate_consultations' => 1, 'can_manage' => 1]);

        $this->actingAs($obs)->post('/consultations', $this->payload($owner))->assertForbidden();

        $this->assertSame(0, Consultation::count());
        $this->assertSame(0, Notification::where('type', 'consultation.assigned')->count());
    }
}
