<?php

namespace Tests\Feature;

use App\Models\Consultation;
use App\Models\Specialty;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Consultation ledger W1, Task 8 — the coordinator capability and the permission predicates.
 *
 * Two guarantees are load-bearing and pinned here:
 *   1. Observers are read-only BEFORE any capability flag is consulted — an Observer carrying the
 *      coordinator flag coordinates nothing.
 *   2. A coordinator does NOT gain sign-off. canManageConsultation() is the sign-off gate and stays
 *      admin / can_manage / owning consultant only.
 */
class ConsultationCoordinatorTest extends TestCase
{
    use RefreshDatabase;

    private function user(int $role = User::ROLE_CONSULTANT, array $extra = []): User
    {
        return User::create(array_merge([
            'username' => 'w1c_' . substr(md5(uniqid('', true)), 0, 10),
            'name' => 'W1 Coord User', 'password' => 'secret12345', 'role' => $role, 'active' => 1,
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
        ], $extra));
    }

    private function admin(): User
    {
        return $this->user(User::ROLE_ADMIN);
    }

    // ---- the predicate ---------------------------------------------------------------------------

    public function test_who_may_coordinate(): void
    {
        $this->assertTrue($this->admin()->canCoordinateConsultations(), 'admins coordinate implicitly');
        $this->assertTrue($this->user(User::ROLE_REGISTRAR, ['can_coordinate_consultations' => true])->canCoordinateConsultations());
        $this->assertFalse($this->user(User::ROLE_CONSULTANT)->canCoordinateConsultations(), 'the flag is off by default');

        // observer-first: the read-only guarantee is checked BEFORE the flag
        $this->assertFalse(
            $this->user(User::ROLE_OBSERVER, ['can_coordinate_consultations' => true])->canCoordinateConsultations(),
            'Observers are read-only regardless of capability flags'
        );
    }

    public function test_visibility_and_modify_predicates(): void
    {
        $cardio = Specialty::create(['name' => 'Cardiology', 'is_subspecialty' => true, 'is_external' => false]);
        $nephro = Specialty::create(['name' => 'Nephrology', 'is_subspecialty' => true, 'is_external' => false]);

        $cardioUser = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);
        $nephroUser = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $nephro->id]);
        $coordinator = $this->user(User::ROLE_REGISTRAR, ['can_coordinate_consultations' => true]);
        $observer = $this->user(User::ROLE_OBSERVER, ['specialty_id' => $cardio->id]);

        $c = Consultation::create([
            'mrn' => '79000001', 'patient_name' => 'Scoped Pt', 'consultation_date' => '2024-05-01',
            'to_service' => 'Cardiology', 'owning_specialty_id' => $cardio->id, 'indication' => [],
            'entered_by' => $coordinator->id,
        ]);

        $this->assertTrue($cardioUser->canSeeConsultation($c));
        $this->assertFalse($nephroUser->canSeeConsultation($c), 'another team must not see this book');
        $this->assertTrue($coordinator->canSeeConsultation($c));
        $this->assertTrue($this->admin()->canSeeConsultation($c));
        $this->assertFalse($observer->canSeeConsultation($c), 'the consultations workspace is closed to Observers');

        $this->assertTrue($cardioUser->canModifyConsultation($c));
        $this->assertTrue($coordinator->canModifyConsultation($c), 'coordinators modify all');
        $this->assertFalse($nephroUser->canModifyConsultation($c));
        $this->assertFalse($observer->canModifyConsultation($c));

        // ---- the NULL-specialty leak guard ------------------------------------------------------
        // 294 of 331 live users have no specialty_id and 119 of 1,283 live consultations have no
        // owning_specialty_id (the backfill resolves owning specialty by name; unmatched rows stay
        // NULL). Drop the `specialty_id !== null` guard in canSeeConsultation() and NULL === NULL
        // matches, handing every one of those users every one of those consults. Unassigned rows are
        // for admins and coordinators only.
        $noSpecialtyUser = $this->user(User::ROLE_CONSULTANT);
        $unassigned = Consultation::create([
            'mrn' => '79000003', 'patient_name' => 'Unassigned Pt', 'consultation_date' => '2024-05-01',
            'to_service' => 'Cardiology', 'owning_specialty_id' => null, 'indication' => [],
            'entered_by' => $coordinator->id,
        ]);

        $this->assertNull($noSpecialtyUser->specialty_id);
        $this->assertNull($unassigned->owning_specialty_id);
        $this->assertFalse(
            $noSpecialtyUser->canSeeConsultation($unassigned),
            'a user with no specialty must not match a consult with no owning specialty'
        );
        $this->assertFalse($noSpecialtyUser->canModifyConsultation($unassigned));

        // ---- the ownership fallback (deliberately WIDER than the specialty rule) -----------------
        // You keep sight of a consult assigned to you, or one you entered, even from another team —
        // otherwise a registrar who books one loses it the moment they save.
        $assignedConsultant = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $nephro->id]);
        $assignedToMe = Consultation::create([
            'mrn' => '79000004', 'patient_name' => 'Assigned Pt', 'consultation_date' => '2024-05-01',
            'to_service' => 'Cardiology', 'owning_specialty_id' => $cardio->id, 'indication' => [],
            'consultant_id' => $assignedConsultant->id, 'entered_by' => $coordinator->id,
        ]);
        $this->assertTrue(
            $assignedConsultant->canSeeConsultation($assignedToMe),
            'the assigned consultant keeps sight of it even from another specialty'
        );

        $enterer = $this->user(User::ROLE_REGISTRAR, ['specialty_id' => $nephro->id]);
        $enteredByMe = Consultation::create([
            'mrn' => '79000005', 'patient_name' => 'Entered Pt', 'consultation_date' => '2024-05-01',
            'to_service' => 'Cardiology', 'owning_specialty_id' => $cardio->id, 'indication' => [],
            'entered_by' => $enterer->id,
        ]);
        $this->assertTrue(
            $enterer->canSeeConsultation($enteredByMe),
            'whoever booked it keeps sight of it even from another specialty'
        );
    }

    public function test_a_coordinator_does_not_gain_sign_off(): void
    {
        $cardio = Specialty::create(['name' => 'Cardiology', 'is_subspecialty' => true, 'is_external' => false]);
        $owner = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);
        $coordinator = $this->user(User::ROLE_REGISTRAR, ['can_coordinate_consultations' => true, 'can_manage' => false]);

        $c = Consultation::create([
            'mrn' => '79000002', 'patient_name' => 'Signoff Pt', 'consultation_date' => '2024-05-02',
            'to_service' => 'Cardiology', 'owning_specialty_id' => $cardio->id,
            'consultant_id' => $owner->id, 'indication' => [],
        ]);

        $this->assertTrue($owner->canManageConsultation($c));
        $this->assertFalse($coordinator->canManageConsultation($c),
            'coordination must never become authority to declare the clinical work finished');

        // and the live endpoint agrees
        $this->actingAs($coordinator)->post("/consultations/{$c->id}/signoff")->assertForbidden();
        $this->assertNull($c->fresh()->signoff_date);
    }

    // ---- Control -> Users wiring -----------------------------------------------------------------

    public function test_admin_can_grant_and_revoke_the_flag_in_control(): void
    {
        $target = $this->user(User::ROLE_REGISTRAR);

        $payload = [
            'username' => $target->username, 'full_name' => 'Coord Target', 'email' => null,
            'role' => User::ROLE_REGISTRAR, 'active' => 1, 'on_service' => 0, 'specialty_id' => null,
            'can_assign' => 0, 'can_add' => 0, 'can_manage' => 0, 'can_modify' => 0,
        ];

        $this->actingAs($this->admin())
            ->put("/control/users/{$target->id}", [...$payload, 'can_coordinate_consultations' => 1])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue((bool) $target->fresh()->can_coordinate_consultations);

        $this->actingAs($this->admin())
            ->put("/control/users/{$target->id}", [...$payload, 'can_coordinate_consultations' => 0])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertFalse((bool) $target->fresh()->can_coordinate_consultations);
    }

    public function test_a_payload_without_the_flag_leaves_it_untouched(): void
    {
        // pins the 'sometimes' rule: pre-existing callers (and the ~2 older tests that post the old
        // capability set) must never silently REVOKE coordination by omission
        $target = $this->user(User::ROLE_REGISTRAR, ['can_coordinate_consultations' => true]);

        $this->actingAs($this->admin())->put("/control/users/{$target->id}", [
            'username' => $target->username, 'full_name' => 'Coord Target', 'email' => null,
            'role' => User::ROLE_REGISTRAR, 'active' => 1, 'on_service' => 0, 'specialty_id' => null,
            'can_assign' => 0, 'can_add' => 0, 'can_manage' => 0, 'can_modify' => 0,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertTrue((bool) $target->fresh()->can_coordinate_consultations);
    }

    public function test_control_ships_the_flag_to_the_users_table(): void
    {
        $target = $this->user(User::ROLE_REGISTRAR, ['can_coordinate_consultations' => true]);

        $this->actingAs($this->admin())->get('/control')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('users', fn ($users) => collect($users)->firstWhere('id', $target->id)['can']['coordinate'] === true));
    }
}
