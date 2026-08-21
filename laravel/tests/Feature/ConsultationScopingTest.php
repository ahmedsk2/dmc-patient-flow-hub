<?php

namespace Tests\Feature;

use App\Models\Consultation;
use App\Models\ConsultationReason;
use App\Models\Specialty;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Consultation ledger W1, Task 10 — server-side specialty scoping.
 *
 * The list is scoped by Consultation::scopeVisibleTo(); creating into another team's book is
 * refused by ConsultationRequest unless the user is a coordinator or an admin. entered_by stays
 * immutable and session-sourced — it can never be set from the request payload.
 */
class ConsultationScopingTest extends TestCase
{
    use RefreshDatabase;

    private function user(int $role = User::ROLE_CONSULTANT, array $extra = []): User
    {
        return User::create(array_merge([
            'username' => 'w1s_' . substr(md5(uniqid('', true)), 0, 10),
            'name' => 'W1 Scope User', 'password' => 'secret12345', 'role' => $role, 'active' => 1,
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
        ], $extra));
    }

    /** @return array{0: Specialty, 1: Specialty} */
    private function specialties(): array
    {
        return [
            Specialty::create(['name' => 'Cardiology', 'is_subspecialty' => true, 'is_external' => false]),
            Specialty::create(['name' => 'Nephrology', 'is_subspecialty' => true, 'is_external' => false]),
        ];
    }

    private function seedThreeBooks(Specialty $cardio, Specialty $nephro): void
    {
        $base = ['consultation_date' => '2026-08-10', 'indication' => [], 'current_location' => 'Ward'];
        Consultation::create([...$base, 'mrn' => '75000001', 'patient_name' => 'Cardio Pt',
            'to_service' => 'Cardiology', 'owning_specialty_id' => $cardio->id]);
        Consultation::create([...$base, 'mrn' => '75000002', 'patient_name' => 'Nephro Pt',
            'to_service' => 'Nephrology', 'owning_specialty_id' => $nephro->id]);
        Consultation::create([...$base, 'mrn' => '75000003', 'patient_name' => 'Unassigned Pt',
            'to_service' => 'Some Outside Clinic', 'owning_specialty_id' => null]);
    }

    public function test_a_team_member_sees_only_their_own_book(): void
    {
        [$cardio, $nephro] = $this->specialties();
        $this->seedThreeBooks($cardio, $nephro);

        $this->actingAs($this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]))
            ->get('/consultations')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('consultations.total', 1)
                ->where('consultations.data.0.mrn', '75000001')
                ->where('stats.total', 1));
    }

    public function test_a_coordinator_and_an_admin_see_every_book(): void
    {
        [$cardio, $nephro] = $this->specialties();
        $this->seedThreeBooks($cardio, $nephro);

        $this->actingAs($this->user(User::ROLE_REGISTRAR, ['can_coordinate_consultations' => true]))
            ->get('/consultations')
            ->assertInertia(fn (AssertableInertia $p) => $p->where('consultations.total', 3));

        $this->actingAs($this->user(User::ROLE_ADMIN))
            ->get('/consultations')
            ->assertInertia(fn (AssertableInertia $p) => $p->where('consultations.total', 3));
    }

    public function test_an_observer_is_still_refused_the_workspace(): void
    {
        [$cardio, $nephro] = $this->specialties();
        $this->seedThreeBooks($cardio, $nephro);

        $this->actingAs($this->user(User::ROLE_OBSERVER, ['specialty_id' => $cardio->id, 'can_coordinate_consultations' => true]))
            ->get('/consultations')->assertForbidden();
    }

    // ---- create ----------------------------------------------------------------------------------

    private function payload(Specialty $target, User $receiving, array $overrides = []): array
    {
        $reason = ConsultationReason::create(['name' => 'W1 Scope Reason']);

        return array_merge([
            'mrn' => '75000100', 'patient_name' => 'New Consult Pt', 'age' => 55, 'bed' => 'W-3',
            'current_location' => 'Ward', 'consultation_date' => now()->toDateString(),
            'consultation_from' => 'ER', 'to_service' => $target->name,
            'consultant_id' => $receiving->id, 'indication' => [$reason->id],
        ], $overrides);
    }

    public function test_a_non_coordinator_cannot_create_into_another_specialty(): void
    {
        [$cardio, $nephro] = $this->specialties();
        $nephroConsultant = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $nephro->id]);
        $cardioUser = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);

        $this->actingAs($cardioUser)->from('/consultations')
            ->post('/consultations', $this->payload($nephro, $nephroConsultant))
            ->assertRedirect('/consultations')
            ->assertSessionHasErrors('to_service');

        $this->assertSame(0, Consultation::count());
    }

    public function test_a_team_member_can_create_into_their_own_specialty_and_it_is_owned_and_stamped(): void
    {
        [$cardio] = $this->specialties();
        $cardioConsultant = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);

        $this->actingAs($cardioConsultant)
            ->post('/consultations', $this->payload($cardio, $cardioConsultant))
            ->assertRedirect()->assertSessionHasNoErrors();

        $c = Consultation::first();
        $this->assertSame($cardio->id, (int) $c->owning_specialty_id, 'the owning team is resolved at entry');
        $this->assertSame($cardioConsultant->id, (int) $c->entered_by);
        $this->assertNotNull($c->requested_at, 'rows created from cutover onward carry a REAL request time');
    }

    public function test_a_coordinator_may_create_into_any_specialty(): void
    {
        [$cardio, $nephro] = $this->specialties();
        $nephroConsultant = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $nephro->id]);
        $coordinator = $this->user(User::ROLE_REGISTRAR, ['can_coordinate_consultations' => true]);

        $this->actingAs($coordinator)
            ->post('/consultations', $this->payload($nephro, $nephroConsultant))
            ->assertRedirect()->assertSessionHasNoErrors();

        $c = Consultation::first();
        $this->assertSame($nephro->id, (int) $c->owning_specialty_id);
        // ownership is owning_specialty_id + consultant_id and is INDEPENDENT of who typed it in
        $this->assertSame($nephroConsultant->id, (int) $c->consultant_id);
        $this->assertSame($coordinator->id, (int) $c->entered_by);
    }

    public function test_entered_by_can_never_be_spoofed_from_the_payload(): void
    {
        [$cardio] = $this->specialties();
        $cardioConsultant = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);
        $someoneElse = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);

        $this->actingAs($cardioConsultant)
            ->post('/consultations', $this->payload($cardio, $cardioConsultant, [
                'entered_by' => $someoneElse->id,
                'owning_specialty_id' => 999,
                'status' => Consultation::STATUS_SIGNED_OFF,
            ]))
            ->assertRedirect()->assertSessionHasNoErrors();

        $c = Consultation::first();
        $this->assertSame($cardioConsultant->id, (int) $c->entered_by, 'entered_by is session-sourced and immutable');
        $this->assertSame($cardio->id, (int) $c->owning_specialty_id, 'the owner is resolved server-side, not accepted');
        $this->assertSame(Consultation::STATUS_NEW, $c->status, 'status is not settable at create time');
    }

    public function test_an_external_or_free_text_service_stays_creatable_and_unassigned(): void
    {
        [$cardio] = $this->specialties();
        $cardioUser = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);

        $payload = $this->payload($cardio, $cardioUser, ['to_service' => 'Some Outside Clinic', 'consultant_id' => null]);

        $this->actingAs($cardioUser)->post('/consultations', $payload)
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertNull(Consultation::first()->owning_specialty_id, 'external referrals have no IM owner');
    }
}
