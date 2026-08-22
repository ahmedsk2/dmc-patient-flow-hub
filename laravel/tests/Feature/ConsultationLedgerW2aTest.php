<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Consultation;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Wave W2A — the four-state consultation model and the sign-off response.
 *
 *  11. POST /consultations/{consultation}/status: legal moves only (new→active, new→ongoing,
 *      active⇄ongoing); sign-off is NOT reachable here; a signed-off consult is frozen; the gate is
 *      User::canModifyConsultation (observers refused first, coordinators allowed).
 *  12. signoff() records status/actor/time + the required structured response.
 *  13. reverseSignoff() returns the consult to `ongoing` and clears the whole response block.
 *  14. The workspace filters by the four statuses, ships live per-status counts, and derives the
 *      ageing of an open consult from requested_at, falling back to consultation_date.
 */
class ConsultationLedgerW2aTest extends TestCase
{
    use RefreshDatabase;

    private function user(int $role = User::ROLE_CONSULTANT, array $extra = []): User
    {
        return User::create(array_merge([
            'username' => 'w2a_' . substr(md5(uniqid('', true)), 0, 10),
            'name' => 'W2a User', 'password' => 'secret12345', 'role' => $role, 'active' => 1,
            'email_verified_at' => now(),
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
        ], $extra));
    }

    private function admin(): User
    {
        return $this->user(User::ROLE_ADMIN);
    }

    /** A coordinator: the new capability, WITHOUT can_manage and without owning the consult. */
    private function coordinator(): User
    {
        return $this->user(User::ROLE_REGISTRAR, [
            'can_coordinate_consultations' => true, 'can_manage' => false,
        ]);
    }

    private function consultation(array $overrides = []): Consultation
    {
        return Consultation::create(array_merge([
            'mrn' => (string) random_int(10000000, 99999999),
            'patient_name' => 'W2a Patient',
            'age' => 55,
            'bed' => 'W-12',
            'current_location' => 'Ward',
            'consultation_from' => 'ER',
            'to_service' => 'Cardiology',
            'consultation_date' => now()->subDay()->toDateString(),
            'indication' => [],
            'status' => Consultation::STATUS_NEW,
        ], $overrides));
    }

    // ---- 11. status-transition endpoint --------------------------------------------------------

    public function test_admin_moves_a_new_consult_to_active_and_the_change_is_audited(): void
    {
        $c = $this->consultation();

        $this->actingAs($this->admin())
            ->post("/consultations/{$c->id}/status", ['status' => Consultation::STATUS_ACTIVE])
            ->assertRedirect()
            ->assertSessionHas('flash.type', 'success');

        $this->assertSame(Consultation::STATUS_ACTIVE, $c->fresh()->status);
        $row = AuditLog::where('action', 'consultation.status_change')
            ->where('entity_id', (string) $c->id)->firstOrFail();
        $this->assertSame(Consultation::STATUS_NEW, $row->details['from']);
        $this->assertSame(Consultation::STATUS_ACTIVE, $row->details['to']);
    }

    public function test_new_can_also_go_straight_to_ongoing(): void
    {
        $c = $this->consultation();

        $this->actingAs($this->admin())
            ->post("/consultations/{$c->id}/status", ['status' => Consultation::STATUS_ONGOING])
            ->assertRedirect();

        $this->assertSame(Consultation::STATUS_ONGOING, $c->fresh()->status);
    }

    public function test_active_and_ongoing_move_both_ways(): void
    {
        $admin = $this->admin();
        $c = $this->consultation(['status' => Consultation::STATUS_ACTIVE]);

        $this->actingAs($admin)->post("/consultations/{$c->id}/status", ['status' => Consultation::STATUS_ONGOING])
            ->assertRedirect();
        $this->assertSame(Consultation::STATUS_ONGOING, $c->fresh()->status);

        $this->actingAs($admin)->post("/consultations/{$c->id}/status", ['status' => Consultation::STATUS_ACTIVE])
            ->assertRedirect();
        $this->assertSame(Consultation::STATUS_ACTIVE, $c->fresh()->status);
    }

    public function test_a_no_op_transition_is_rejected_with_422(): void
    {
        $c = $this->consultation(['status' => Consultation::STATUS_ACTIVE]);

        $this->actingAs($this->admin())
            ->post("/consultations/{$c->id}/status", ['status' => Consultation::STATUS_ACTIVE])
            ->assertStatus(422)
            ->assertJsonPath('errors.status.0', 'A consultation cannot move from active to active.');

        $this->assertSame(Consultation::STATUS_ACTIVE, $c->fresh()->status);
    }

    public function test_sign_off_is_not_reachable_through_the_status_endpoint(): void
    {
        $c = $this->consultation(['status' => Consultation::STATUS_ACTIVE]);

        $this->actingAs($this->admin())
            ->post("/consultations/{$c->id}/status", ['status' => Consultation::STATUS_SIGNED_OFF])
            ->assertStatus(422);

        $this->assertSame(Consultation::STATUS_ACTIVE, $c->fresh()->status);
        $this->assertNull($c->fresh()->signoff_date, 'the sign-off action is the ONLY way to sign off');
    }

    public function test_a_signed_off_consult_is_frozen_for_this_endpoint(): void
    {
        $c = $this->consultation([
            'status' => Consultation::STATUS_SIGNED_OFF, 'signoff_date' => now()->toDateString(),
        ]);

        $this->actingAs($this->admin())
            ->post("/consultations/{$c->id}/status", ['status' => Consultation::STATUS_ONGOING])
            ->assertStatus(422)
            ->assertJsonPath('errors.status.0', 'A signed-off consultation cannot be moved. An admin must reverse the sign-off first.');

        $this->assertSame(Consultation::STATUS_SIGNED_OFF, $c->fresh()->status);
    }

    public function test_observers_can_never_change_a_status_even_with_capability_flags(): void
    {
        $c = $this->consultation();

        $this->actingAs($this->user(User::ROLE_OBSERVER, ['can_manage' => true, 'can_modify' => true,
            'can_coordinate_consultations' => true]))
            ->post("/consultations/{$c->id}/status", ['status' => Consultation::STATUS_ACTIVE])
            ->assertForbidden();

        $this->assertSame(Consultation::STATUS_NEW, $c->fresh()->status);
    }

    public function test_a_coordinator_may_change_the_status_of_any_specialtys_consult(): void
    {
        $c = $this->consultation();

        $this->actingAs($this->coordinator())
            ->post("/consultations/{$c->id}/status", ['status' => Consultation::STATUS_ACTIVE])
            ->assertRedirect();

        $this->assertSame(Consultation::STATUS_ACTIVE, $c->fresh()->status);
    }
}
