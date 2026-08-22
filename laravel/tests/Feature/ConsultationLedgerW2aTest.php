<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Consultation;
use App\Models\Specialty;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
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

    /**
     * The fabricated-row case above pins the SHAPE a signed-off consult will have once signoff()
     * writes `status` too (Task 12). This case proves the FREEZE itself, by reaching the state the
     * way a clinician does — through the real sign-off endpoint — so the guard can never be green
     * on a state no code path produces while a genuinely signed-off consult is silently reopened.
     */
    public function test_a_consult_signed_off_through_the_real_endpoint_is_frozen(): void
    {
        $admin = $this->admin();
        $c = $this->consultation(['status' => Consultation::STATUS_ACTIVE]);

        // W2A (Task 12): sign-off now carries a REQUIRED structured response, so the fixture step
        // posts one. What this case actually pins — the freeze — is unchanged.
        $this->actingAs($admin)->post("/consultations/{$c->id}/signoff",
            ['response_disposition' => 'advice_given'])->assertRedirect();
        $this->assertNotNull($c->fresh()->signoff_date, 'fixture guard: the sign-off must have landed');

        $this->actingAs($admin)
            ->post("/consultations/{$c->id}/status", ['status' => Consultation::STATUS_ONGOING])
            ->assertStatus(422)
            ->assertJsonPath('errors.status.0', 'A signed-off consultation cannot be moved. An admin must reverse the sign-off first.');

        $after = $c->fresh();
        $this->assertNotSame(Consultation::STATUS_ONGOING, $after->status,
            'a signed-off consultation must never be reopened through the status endpoint');
        $this->assertNotNull($after->signoff_date);
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

    /**
     * The gate is canModifyConsultation — W1 SPECIALTY scoping, not a bare observer check and not
     * the coordinator flag. These two cases are what distinguish them: a plain consultant of the
     * owning team may move the consult, and a plain consultant of another team may not.
     */
    public function test_a_consultant_of_the_owning_specialty_may_change_the_status(): void
    {
        $cardio = Specialty::create(['name' => 'Cardiology', 'is_subspecialty' => true, 'is_external' => false]);
        $c = $this->consultation(['owning_specialty_id' => $cardio->id]);

        $this->actingAs($this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]))
            ->post("/consultations/{$c->id}/status", ['status' => Consultation::STATUS_ACTIVE])
            ->assertRedirect();

        $this->assertSame(Consultation::STATUS_ACTIVE, $c->fresh()->status);
    }

    public function test_a_consultant_of_another_specialty_may_not_change_the_status(): void
    {
        $cardio = Specialty::create(['name' => 'Cardiology', 'is_subspecialty' => true, 'is_external' => false]);
        $nephro = Specialty::create(['name' => 'Nephrology', 'is_subspecialty' => true, 'is_external' => false]);
        $c = $this->consultation(['owning_specialty_id' => $cardio->id]);

        $this->actingAs($this->user(User::ROLE_CONSULTANT, ['specialty_id' => $nephro->id]))
            ->post("/consultations/{$c->id}/status", ['status' => Consultation::STATUS_ACTIVE])
            ->assertForbidden();

        $this->assertSame(Consultation::STATUS_NEW, $c->fresh()->status);
    }

    // ---- 12. sign-off writes the response --------------------------------------------------------

    public function test_signoff_records_status_actor_time_and_the_structured_response(): void
    {
        $owner = $this->user();
        $c = $this->consultation(['status' => Consultation::STATUS_ACTIVE, 'consultant_id' => $owner->id]);

        $this->actingAs($owner)->post("/consultations/{$c->id}/signoff", [
            'response_disposition' => 'advice_given',
            'response_followup_needed' => true,
            'response_note' => 'Continue beta blocker, repeat echo in 6 weeks.',
        ])->assertRedirect()->assertSessionHas('flash.type', 'success');

        $c->refresh();
        $this->assertSame(Consultation::STATUS_SIGNED_OFF, $c->status);
        $this->assertSame(now()->toDateString(), $c->signoff_date?->toDateString());
        $this->assertNotNull($c->signed_off_at);
        $this->assertSame($owner->id, (int) $c->signed_off_by);
        $this->assertSame('advice_given', $c->response_disposition);
        $this->assertTrue((bool) $c->response_followup_needed);
        $this->assertSame('Continue beta blocker, repeat echo in 6 weeks.', $c->response_note);
        $this->assertTrue(AuditLog::where('action', 'consultation.signoff')
            ->where('entity_id', (string) $c->id)->exists());
    }

    public function test_signoff_requires_a_disposition(): void
    {
        $owner = $this->user();
        $c = $this->consultation(['status' => Consultation::STATUS_ACTIVE, 'consultant_id' => $owner->id]);

        $this->actingAs($owner)->from('/consultations')
            ->post("/consultations/{$c->id}/signoff", ['response_note' => 'no disposition given'])
            ->assertRedirect('/consultations')
            ->assertSessionHasErrors('response_disposition');

        $c->refresh();
        $this->assertNull($c->signoff_date, 'a consult must never be signed off without a recorded response');
        $this->assertSame(Consultation::STATUS_ACTIVE, $c->status);
    }

    public function test_signoff_rejects_a_disposition_outside_the_vocabulary(): void
    {
        $owner = $this->user();
        $c = $this->consultation(['status' => Consultation::STATUS_ACTIVE, 'consultant_id' => $owner->id]);

        $this->actingAs($owner)->from('/consultations')
            ->post("/consultations/{$c->id}/signoff", ['response_disposition' => 'handed_to_surgery'])
            ->assertRedirect('/consultations')
            ->assertSessionHasErrors('response_disposition');

        $this->assertNull($c->fresh()->signoff_date);
    }

    public function test_a_coordinator_is_refused_sign_off(): void
    {
        $c = $this->consultation(['status' => Consultation::STATUS_ACTIVE, 'consultant_id' => $this->user()->id]);

        $this->actingAs($this->coordinator())
            ->post("/consultations/{$c->id}/signoff", ['response_disposition' => 'advice_given'])
            ->assertForbidden();

        $c->refresh();
        $this->assertNull($c->signoff_date, 'coordinating is booking work, not asserting it is done');
        $this->assertNull($c->response_disposition);
        $this->assertSame(Consultation::STATUS_ACTIVE, $c->status);
    }

    public function test_an_already_signed_off_consult_is_refused_without_overwriting_the_response(): void
    {
        $owner = $this->user();
        $c = $this->consultation([
            'status' => Consultation::STATUS_SIGNED_OFF, 'consultant_id' => $owner->id,
            'signoff_date' => now()->subDay()->toDateString(), 'response_disposition' => 'no_further_input',
        ]);

        $this->actingAs($owner)->post("/consultations/{$c->id}/signoff", [
            'response_disposition' => 'taking_over',
        ])->assertRedirect()->assertSessionHas('flash.type', 'error');

        $this->assertSame('no_further_input', $c->fresh()->response_disposition);
    }

    /**
     * Sign-off now writes a whole block (status + actor + time + response), so undoing it must undo
     * ALL of it. A row left saying "Dr X closed this at 14:02, advice given" while signoff_date is
     * NULL is a misattributed clinical record — and it would also freeze the consult forever, since
     * the status endpoint refuses to move anything still carrying `status = signed_off`.
     */
    public function test_reversing_a_signoff_clears_the_whole_response_block_and_reopens_the_consult(): void
    {
        $admin = $this->admin();
        $c = $this->consultation(['status' => Consultation::STATUS_ACTIVE, 'consultant_id' => $admin->id]);

        $this->actingAs($admin)->post("/consultations/{$c->id}/signoff", [
            'response_disposition' => 'advice_given',
            'response_followup_needed' => true,
            'response_note' => 'Repeat echo in 6 weeks.',
        ])->assertRedirect()->assertSessionHas('flash.type', 'success');

        $this->actingAs($admin)->post("/consultations/{$c->id}/reverse-signoff")
            ->assertRedirect()->assertSessionHas('flash.type', 'success');

        $c->refresh();
        $this->assertNull($c->signoff_date);
        $this->assertSame(Consultation::STATUS_ONGOING, $c->status,
            'a reversed sign-off must not leave the row claiming it is closed');
        $this->assertNull($c->signed_off_at);
        $this->assertNull($c->signed_off_by, 'the reversed record must not keep naming a signer');
        $this->assertNull($c->response_disposition);
        $this->assertNull($c->response_followup_needed);
        $this->assertNull($c->response_note);

        // and it is genuinely back on the books: the status endpoint no longer freezes it
        $this->actingAs($admin)
            ->post("/consultations/{$c->id}/status", ['status' => Consultation::STATUS_ACTIVE])
            ->assertRedirect()->assertSessionHas('flash.type', 'success');
        $this->assertSame(Consultation::STATUS_ACTIVE, $c->fresh()->status);
    }

    // ---- 13. reverse sign-off ---------------------------------------------------------------------

    public function test_reverse_signoff_returns_the_consult_to_ongoing_and_clears_the_response(): void
    {
        $owner = $this->user();
        $c = $this->consultation([
            'status' => Consultation::STATUS_SIGNED_OFF,
            'consultant_id' => $owner->id,
            'signoff_date' => now()->toDateString(),
            'signed_off_at' => now(),
            'signed_off_by' => $owner->id,
            'response_disposition' => 'taking_over',
            'response_followup_needed' => true,
            'response_note' => 'signed off in error',
        ]);

        $this->actingAs($this->admin())
            ->post("/consultations/{$c->id}/reverse-signoff")
            ->assertRedirect()->assertSessionHas('flash.type', 'success');

        $c->refresh();
        $this->assertSame(Consultation::STATUS_ONGOING, $c->status, 'a reopened consult is on the books, not a daily commitment');
        $this->assertNull($c->signoff_date);
        $this->assertNull($c->signed_off_at);
        $this->assertNull($c->signed_off_by);
        $this->assertNull($c->response_disposition);
        $this->assertNull($c->response_followup_needed);
        $this->assertNull($c->response_note);
        $this->assertTrue(AuditLog::where('action', 'consultation.reverse_signoff')
            ->where('entity_id', (string) $c->id)->exists());
    }

    public function test_reverse_signoff_of_an_older_signoff_changes_nothing_at_all(): void
    {
        $c = $this->consultation([
            'status' => Consultation::STATUS_SIGNED_OFF,
            'signoff_date' => now()->subDays(3)->toDateString(),
            'signed_off_at' => now()->subDays(3),
            'response_disposition' => 'advice_given',
        ]);

        $this->actingAs($this->admin())
            ->post("/consultations/{$c->id}/reverse-signoff")
            ->assertRedirect()->assertSessionHas('flash.type', 'error');

        $c->refresh();
        $this->assertSame(Consultation::STATUS_SIGNED_OFF, $c->status);
        $this->assertNotNull($c->signoff_date, 'older sign-offs are history, not mistakes');
        $this->assertSame('advice_given', $c->response_disposition);
    }

    public function test_a_non_admin_cannot_reverse_a_sign_off(): void
    {
        $owner = $this->user();
        $c = $this->consultation([
            'status' => Consultation::STATUS_SIGNED_OFF, 'consultant_id' => $owner->id,
            'signoff_date' => now()->toDateString(), 'response_disposition' => 'advice_given',
        ]);

        $this->actingAs($owner)->post("/consultations/{$c->id}/reverse-signoff")->assertForbidden();
        $this->actingAs($this->coordinator())->post("/consultations/{$c->id}/reverse-signoff")->assertForbidden();

        $this->assertSame(Consultation::STATUS_SIGNED_OFF, $c->fresh()->status);
        $this->assertNotNull($c->fresh()->signoff_date);
    }

    // ---- 14. four status tabs, live counts, ageing -----------------------------------------------

    public function test_the_workspace_filters_by_status_and_ships_a_live_count_per_tab(): void
    {
        $this->consultation(['status' => Consultation::STATUS_NEW]);
        $this->consultation(['status' => Consultation::STATUS_ACTIVE]);
        $this->consultation(['status' => Consultation::STATUS_ACTIVE]);
        $this->consultation(['status' => Consultation::STATUS_ONGOING]);
        $this->consultation(['status' => Consultation::STATUS_SIGNED_OFF, 'signoff_date' => now()->toDateString()]);

        $this->actingAs($this->admin())->get('/consultations?status=active')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->component('Consultations/Index')
                ->where('filters.status', 'active')
                ->where('consultations.total', 2)
                ->where('stats.new', 1)
                ->where('stats.active', 2)
                ->where('stats.ongoing', 1)
                ->where('stats.signed_off', 1)
                ->where('stats.total', 5));

        $this->actingAs($this->admin())->get('/consultations?status=ongoing')
            ->assertInertia(fn (AssertableInertia $p) => $p->where('consultations.total', 1));

        $this->actingAs($this->admin())->get('/consultations?status=signed_off')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('consultations.total', 1)
                ->where('consultations.data.0.open_days', null));
    }

    public function test_an_unknown_status_falls_back_to_the_new_tab(): void
    {
        $this->consultation(['status' => Consultation::STATUS_NEW]);
        $this->consultation(['status' => Consultation::STATUS_ONGOING]);

        $this->actingAs($this->admin())->get('/consultations?status=signed')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('filters.status', 'new')
                ->where('consultations.total', 1));
    }

    public function test_open_days_uses_requested_at_when_it_is_present(): void
    {
        $this->consultation([
            'status' => Consultation::STATUS_ONGOING,
            'requested_at' => now()->subDays(6),
            'consultation_date' => now()->subDays(30)->toDateString(),   // must be ignored
        ]);

        $this->actingAs($this->admin())->get('/consultations?status=ongoing')
            ->assertInertia(fn (AssertableInertia $p) => $p->where('consultations.data.0.open_days', 6));
    }

    public function test_open_days_falls_back_to_consultation_date_for_historical_rows(): void
    {
        $this->consultation([
            'status' => Consultation::STATUS_ONGOING,
            'requested_at' => null,                                       // every legacy row
            'consultation_date' => now()->subDays(3)->toDateString(),
        ]);

        $this->actingAs($this->admin())->get('/consultations?status=ongoing')
            ->assertInertia(fn (AssertableInertia $p) => $p->where('consultations.data.0.open_days', 3));
    }

    public function test_a_row_with_neither_timestamp_reports_no_ageing_rather_than_zero(): void
    {
        $this->consultation([
            'status' => Consultation::STATUS_ONGOING, 'requested_at' => null, 'consultation_date' => null,
        ]);

        $this->actingAs($this->admin())->get('/consultations?status=ongoing')
            ->assertInertia(fn (AssertableInertia $p) => $p->where('consultations.data.0.open_days', null));
    }
}
