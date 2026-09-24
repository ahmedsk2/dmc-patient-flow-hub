<?php

namespace Tests\Feature;

use App\Http\Requests\ConsultationRequest;
use App\Models\Specialty;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Role/UX review 2026-09-24, group g4-consultations.
 *
 * Fix #4: the own-specialty booking rule (ConsultationRequest::ownSpecialtyRule) was always
 * server-enforced, but the "To service" datalist offered every specialty regardless of who was
 * looking, and the refusal only ever surfaced after the whole form had been filled in. This test
 * pins the three new page props ConsultationsController::index() now ships so the workspace can
 * say the rule up front: `canBookAnyTeam`, `bookableToServices` and `bookingNotice`. The wording
 * asserted here is byte-for-byte the SAME wording ownSpecialtyRule() actually fails validation
 * with (ConsultationRequest::NO_SPECIALTY_MESSAGE / OWN_SPECIALTY_ONLY_MESSAGE) — the two must
 * never drift apart.
 */
class UxReviewG4ConsultationsTest extends TestCase
{
    use RefreshDatabase;

    private function user(int $role = User::ROLE_CONSULTANT, array $extra = []): User
    {
        return User::create(array_merge([
            'username' => 'ux4_'.substr(md5(uniqid('', true)), 0, 10),
            'name' => 'UX4 User', 'password' => 'secret12345', 'role' => $role, 'active' => 1,
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
            'email_verified_at' => now(), 'pass_exp_date' => now()->toDateString(),
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

    public function test_admin_may_book_into_any_team_with_no_restriction_notice(): void
    {
        [$cardio, $nephro] = $this->specialties();

        $this->actingAs($this->user(User::ROLE_ADMIN))
            ->get('/consultations')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('canBookAnyTeam', true)
                ->where('bookingNotice', null)
                ->has('bookableToServices', 2)
                ->where('bookableToServices.0.name', $cardio->name)
                ->where('bookableToServices.1.name', $nephro->name));
    }

    public function test_coordinator_may_book_into_any_team_even_with_no_specialty(): void
    {
        $this->specialties();
        $coordinator = $this->user(User::ROLE_REGISTRAR, [
            'specialty_id' => null, 'can_coordinate_consultations' => true,
        ]);

        $this->actingAs($coordinator)
            ->get('/consultations')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('canBookAnyTeam', true)
                ->where('bookingNotice', null)
                ->has('bookableToServices', 2));
    }

    public function test_a_specialty_bound_user_is_offered_only_their_own_team_with_the_own_specialty_notice(): void
    {
        [$cardio] = $this->specialties();
        $consultant = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);

        $this->actingAs($consultant)
            ->get('/consultations')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('canBookAnyTeam', false)
                ->has('bookableToServices', 1)
                ->where('bookableToServices.0.id', $cardio->id)
                ->where('bookableToServices.0.name', $cardio->name)
                ->where('bookingNotice', ConsultationRequest::OWN_SPECIALTY_ONLY_MESSAGE));
    }

    public function test_a_user_with_no_specialty_and_no_coordinator_capability_is_offered_nothing_with_the_no_specialty_notice(): void
    {
        $this->specialties();
        $registrar = $this->user(User::ROLE_REGISTRAR, [
            'specialty_id' => null, 'can_coordinate_consultations' => false,
        ]);

        $this->actingAs($registrar)
            ->get('/consultations')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('canBookAnyTeam', false)
                ->has('bookableToServices', 0)
                ->where('bookingNotice', ConsultationRequest::NO_SPECIALTY_MESSAGE));
    }

    /**
     * The notice text shipped to the page must be the EXACT string the request validator fails
     * with — proving the two can never silently diverge, since a re-worded notice that no longer
     * matches the real refusal would be worse than no notice at all.
     */
    public function test_the_page_notice_matches_the_actual_validation_refusal(): void
    {
        [$cardio, $nephro] = $this->specialties();
        $cardioUser = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);

        $this->actingAs($cardioUser)
            ->get('/consultations')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('bookingNotice', ConsultationRequest::OWN_SPECIALTY_ONLY_MESSAGE));

        // the SAME user, attempting to actually book into Nephrology, is refused with the SAME
        // sentence the page notice already showed them
        $this->actingAs($cardioUser)
            ->post('/consultations', [
                'mrn' => '75009001', 'patient_name' => 'Notice Match Patient', 'age' => 40,
                'bed' => 'W-1', 'current_location' => 'Ward',
                'consultation_date' => now()->toDateString(), 'consultation_from' => 'ER',
                'to_service' => $nephro->name, 'indication' => [1], 'unmatched_mrn_ack' => true,
            ])
            ->assertSessionHasErrors(['to_service' => ConsultationRequest::OWN_SPECIALTY_ONLY_MESSAGE]);
    }
}
