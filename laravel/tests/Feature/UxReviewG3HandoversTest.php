<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\AuditLog;
use App\Models\HandoverSignature;
use App\Models\Notification;
use App\Models\Patient;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * 2026-09-24 role/UX review, group g3-handovers:
 *
 *  #12 — a single-row Sign used to succeed with no confirmation and no server-side evidence the
 *        signer read the note (unlike "Sign all", which already confirmed client-side). The client
 *        (Handovers/Index.vue) now confirms every sign through the same themed dialog and sends an
 *        explicit `acknowledged` flag; HandoverController::sign()/signMany() now refuse a request
 *        missing it. These tests cover the server side of that — the confirm dialog itself is
 *        covered by resources/js/Pages/Handovers/__tests__/Index.spec.js.
 *
 *  #32 — the inbox "Write" button used to render for every needsHandover row, including for an
 *        Observer who can never write one. HandoverController::index() now ships a per-row
 *        `can_write` flag computed from the SAME two grants HandoverController::save() enforces —
 *        User::canManageAdmission() OR the still-pending-outgoing-consultant branch — these tests
 *        confirm the shipped flag actually matches that rule, since the client only hides the
 *        button (see #32) — the server remains the enforcement (Auth::user()->isObserver() /
 *        canManageAdmission()-or-$isOutgoing in save()). Fix-up round (adversarial review):
 *        the first pass only mirrored canManageAdmission() and missed the $isOutgoing half —
 *        see the two outgoing-consultant tests below.
 */
class UxReviewG3HandoversTest extends TestCase
{
    use RefreshDatabase;

    private function user(array $overrides = []): User
    {
        return User::create(array_merge([
            'username' => 'g3_'.substr(md5(uniqid('', true)), 0, 10),
            'name' => 'G3 User', 'password' => 'secret12345', 'role' => User::ROLE_CONSULTANT, 'active' => 1,
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
        ], $overrides));
    }

    private function admission(array $overrides = []): Admission
    {
        $p = Patient::create(['mrn' => (string) random_int(10000000, 99999999), 'name' => 'G3 Patient']);

        return Admission::create(array_merge([
            'patient_id' => $p->id, 'admit_date' => now()->subDays(2)->toDateString(),
            'current_location' => 'Ward', 'is_longterm' => 0, 'is_new_assignment' => 0,
        ], $overrides));
    }

    private function flagNeedsHandover(Admission $a, User $recipient): void
    {
        Notification::create(['user_id' => $recipient->id, 'type' => 'handover.incomplete',
            'created_at' => now(), 'admission_id' => $a->id, 'payload' => ['admission_id' => $a->id]]);
    }

    // ---- #12: sign requires an explicit acknowledgement --------------------------------------

    public function test_sign_without_acknowledgement_is_refused_and_leaves_signature_unsigned(): void
    {
        $from = $this->user();
        $to = $this->user();
        $a = $this->admission(['consultant_id' => $to->id]);
        $sig = HandoverSignature::create(['admission_id' => $a->id, 'from_consultant_id' => $from->id,
            'to_consultant_id' => $to->id, 'required_at' => now()]);

        $this->actingAs($to)->post("/handovers/{$sig->id}/sign")
            ->assertRedirect()->assertSessionHas('flash.type', 'error');

        $sig->refresh();
        $this->assertNull($sig->signed_at, 'a request missing acknowledged must not sign');
        $this->assertFalse(AuditLog::where('action', 'handover.sign')->where('entity_id', (string) $a->id)->exists());
    }

    public function test_sign_with_acknowledgement_false_is_also_refused(): void
    {
        $to = $this->user();
        $a = $this->admission(['consultant_id' => $to->id]);
        $sig = HandoverSignature::create(['admission_id' => $a->id, 'from_consultant_id' => $this->user()->id,
            'to_consultant_id' => $to->id, 'required_at' => now()]);

        $this->actingAs($to)->post("/handovers/{$sig->id}/sign", ['acknowledged' => false])
            ->assertRedirect()->assertSessionHas('flash.type', 'error');

        $this->assertNull($sig->fresh()->signed_at);
    }

    public function test_sign_with_acknowledgement_signs(): void
    {
        $to = $this->user();
        $a = $this->admission(['consultant_id' => $to->id]);
        $sig = HandoverSignature::create(['admission_id' => $a->id, 'from_consultant_id' => $this->user()->id,
            'to_consultant_id' => $to->id, 'required_at' => now()]);

        $this->actingAs($to)->post("/handovers/{$sig->id}/sign", ['acknowledged' => true])
            ->assertRedirect()->assertSessionHasNoErrors();

        $sig->refresh();
        $this->assertNotNull($sig->signed_at);
        $this->assertSame($to->id, (int) $sig->signed_by);
        $this->assertTrue(AuditLog::where('action', 'handover.sign')->where('entity_id', (string) $a->id)->exists());
    }

    public function test_sign_many_without_acknowledgement_is_refused_and_leaves_signatures_unsigned(): void
    {
        $me = $this->user();
        $a = $this->admission(['consultant_id' => $me->id]);
        $sig = HandoverSignature::create(['admission_id' => $a->id, 'from_consultant_id' => $this->user()->id,
            'to_consultant_id' => $me->id, 'required_at' => now()]);

        $this->actingAs($me)->post('/handovers/sign-many', ['ids' => [$sig->id]])
            ->assertRedirect()->assertSessionHas('flash.type', 'error');

        $this->assertNull($sig->fresh()->signed_at, 'sign-many without acknowledged must sign nothing');
    }

    public function test_sign_many_with_acknowledgement_signs_all_mine(): void
    {
        $me = $this->user();
        $a1 = $this->admission(['consultant_id' => $me->id]);
        $a2 = $this->admission(['consultant_id' => $me->id]);
        $s1 = HandoverSignature::create(['admission_id' => $a1->id, 'from_consultant_id' => $this->user()->id,
            'to_consultant_id' => $me->id, 'required_at' => now()]);
        $s2 = HandoverSignature::create(['admission_id' => $a2->id, 'from_consultant_id' => $this->user()->id,
            'to_consultant_id' => $me->id, 'required_at' => now()]);

        $this->actingAs($me)->post('/handovers/sign-many', ['ids' => [$s1->id, $s2->id], 'acknowledged' => true])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertNotNull($s1->fresh()->signed_at);
        $this->assertNotNull($s2->fresh()->signed_at);
    }

    // ---- #32: needsHandover ships a per-row can_write mirroring canManageAdmission -----------

    public function test_needs_handover_can_write_true_for_admin_and_a_capability_holder_but_false_for_observer(): void
    {
        $owner = $this->user();
        $a = $this->admission(['consultant_id' => $owner->id]);
        $this->flagNeedsHandover($a, $owner);

        $admin = $this->user(['role' => User::ROLE_ADMIN]);
        $observer = $this->user(['role' => User::ROLE_OBSERVER]);
        // a non-owning Resident with the Manage capability: canManageAdmission() grants write via
        // the capability flag alone, NOT via ownership — this exercises that branch specifically.
        $manager = $this->user(['role' => User::ROLE_RESIDENT, 'can_manage' => true]);

        $this->actingAs($admin)->get('/handovers')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->where('needsHandover.0.admission_id', $a->id)->where('needsHandover.0.can_write', true));

        $this->actingAs($observer)->get('/handovers')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->where('needsHandover.0.admission_id', $a->id)->where('needsHandover.0.can_write', false));

        $this->actingAs($manager)->get('/handovers')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->where('needsHandover.0.admission_id', $a->id)->where('needsHandover.0.can_write', true));
    }

    public function test_needs_handover_can_write_true_for_the_owning_consultant_who_only_sees_their_own_row(): void
    {
        $owner = $this->user();
        $mine = $this->admission(['consultant_id' => $owner->id]);
        $this->flagNeedsHandover($mine, $owner);

        $other = $this->user();
        $theirs = $this->admission(['consultant_id' => $other->id]);
        $this->flagNeedsHandover($theirs, $other);

        // D1 own-only scope: a plain consultant sees only their own row, and can_write is true on it
        // (they are its primary consultant).
        $this->actingAs($owner)->get('/handovers')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->has('needsHandover', 1)
            ->where('needsHandover.0.admission_id', $mine->id)
            ->where('needsHandover.0.can_write', true));
    }

    public function test_needs_handover_can_write_is_false_for_a_registrar_with_no_capability_on_someone_elses_patient(): void
    {
        $owner = $this->user();
        $a = $this->admission(['consultant_id' => $owner->id]);
        $this->flagNeedsHandover($a, $owner);

        // Registrar is unit-wide (not D1-scoped) but has neither can_manage nor ownership of this
        // admission — canManageAdmission() must return false, and the row must say so.
        $registrar = $this->user(['role' => User::ROLE_REGISTRAR]);

        $this->actingAs($registrar)->get('/handovers')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->where('needsHandover.0.admission_id', $a->id)->where('needsHandover.0.can_write', false));
    }

    // Review fix-up (#32): can_write must mirror save()'s FULL grant, including the "still-pending
    // outgoing consultant" branch ($isOutgoing), not just canManageAdmission().
    public function test_needs_handover_can_write_true_for_the_outgoing_consultant_of_a_still_pending_signature(): void
    {
        $newOwner = $this->user();
        $a = $this->admission(['consultant_id' => $newOwner->id]);
        $this->flagNeedsHandover($a, $newOwner);

        // A Resident (unit-wide, NOT D1-scoped — unlike a plain Consultant) who was reassigned OFF
        // this admission but still has a pending outgoing signature on it: canManageAdmission() is
        // false (no capability, not the owner), yet save() grants them a write via $isOutgoing, so
        // can_write must be true, and they must actually see the row (they are unit-wide).
        $formerResident = $this->user(['role' => User::ROLE_RESIDENT]);
        HandoverSignature::create(['admission_id' => $a->id, 'from_consultant_id' => $formerResident->id,
            'to_consultant_id' => $newOwner->id, 'required_at' => now()]);

        $this->assertFalse($formerResident->fresh()->canManageAdmission($a->fresh()),
            'precondition: canManageAdmission() alone must be false for this fixture');

        $this->actingAs($formerResident)->get('/handovers')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->where('needsHandover.0.admission_id', $a->id)->where('needsHandover.0.can_write', true));
    }

    public function test_needs_handover_can_write_stays_false_when_the_outgoing_signature_is_already_signed(): void
    {
        $owner = $this->user();
        $a = $this->admission(['consultant_id' => $owner->id]);
        $this->flagNeedsHandover($a, $owner);

        $formerResident = $this->user(['role' => User::ROLE_RESIDENT]);
        // Already signed — no longer "pending", so it must NOT grant can_write (matches save()'s
        // ->pending() scope, which only matches unsigned/unvoided signatures).
        HandoverSignature::create(['admission_id' => $a->id, 'from_consultant_id' => $formerResident->id,
            'to_consultant_id' => $owner->id, 'required_at' => now(), 'signed_at' => now(), 'signed_by' => $owner->id]);

        $this->actingAs($formerResident)->get('/handovers')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->where('needsHandover.0.admission_id', $a->id)->where('needsHandover.0.can_write', false));
    }
}
