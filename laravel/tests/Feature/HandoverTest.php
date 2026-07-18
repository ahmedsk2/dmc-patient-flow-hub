<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\AuditLog;
use App\Models\Handover;
use App\Models\HandoverRevision;
use App\Models\HandoverSignature;
use App\Models\Notification;
use App\Models\Patient;
use App\Models\Specialty;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Handover feature: per-admission handover text (+ append-only revisions), the same-day
 * handover gate on consultant-changing transfers (assign / bulk reassign / specialty transfer),
 * receiving-consultant signatures (created in the transfer transaction, superseding prior
 * unsigned ones, auto-voided on discharge/delete), notifications, and the inbox/preflight APIs.
 */
class HandoverTest extends TestCase
{
    use RefreshDatabase;

    private function user(array $overrides = []): User
    {
        return User::create(array_merge([
            'username' => 'ho_' . substr(md5(uniqid('', true)), 0, 8),
            'name' => 'HO User', 'password' => 'secret12345', 'role' => User::ROLE_CONSULTANT, 'active' => 1,
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
        ], $overrides));
    }

    private function admission(array $overrides = []): Admission
    {
        $p = Patient::create(['mrn' => (string) random_int(10000000, 99999999), 'name' => 'HO Patient']);

        return Admission::create(array_merge([
            'patient_id' => $p->id, 'admit_date' => now()->subDays(2)->toDateString(),
            'current_location' => 'Ward', 'is_longterm' => 0, 'is_new_assignment' => 0,
        ], $overrides));
    }

    /** Seed a handover updated TODAY (the state that satisfies the transfer gate). */
    private function freshHandover(Admission $a, User $author, string $body = 'Stable. Continue plan.'): Handover
    {
        $h = Handover::create(['admission_id' => $a->id, 'body' => $body, 'updated_by' => $author->id]);
        HandoverRevision::create(['admission_id' => $a->id, 'body' => $body, 'author_id' => $author->id]);

        return $h;
    }

    // ---- 1. save handover --------------------------------------------------------------------

    public function test_save_creates_handover_revision_and_audit(): void
    {
        $owner = $this->user();
        $a = $this->admission(['consultant_id' => $owner->id]);

        $this->actingAs($owner)->post("/admissions/{$a->id}/handover", ['body' => 'Day 2: weaning O2.'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $h = Handover::where('admission_id', $a->id)->first();
        $this->assertNotNull($h);
        $this->assertSame('Day 2: weaning O2.', $h->body);
        $this->assertSame($owner->id, (int) $h->updated_by);
        $this->assertSame(1, HandoverRevision::where('admission_id', $a->id)->count());
        $this->assertSame($owner->id, (int) HandoverRevision::where('admission_id', $a->id)->first()->author_id);
        $this->assertTrue(AuditLog::where('action', 'handover.update')->where('entity_id', (string) $a->id)->exists());
    }

    public function test_second_save_updates_current_text_and_appends_revision(): void
    {
        $owner = $this->user();
        $a = $this->admission(['consultant_id' => $owner->id]);

        $this->actingAs($owner)->post("/admissions/{$a->id}/handover", ['body' => 'v1'])->assertRedirect();
        $this->actingAs($owner)->post("/admissions/{$a->id}/handover", ['body' => 'v2'])->assertRedirect();

        $this->assertSame(1, Handover::where('admission_id', $a->id)->count(), 'one current row per admission');
        $this->assertSame('v2', Handover::where('admission_id', $a->id)->value('body'));
        $this->assertSame(['v1', 'v2'], HandoverRevision::where('admission_id', $a->id)->orderBy('id')->pluck('body')->all());
    }

    public function test_non_owner_without_manage_cannot_save(): void
    {
        $owner = $this->user();
        $other = $this->user();   // a consultant, but not this patient's
        $a = $this->admission(['consultant_id' => $owner->id]);

        $this->actingAs($other)->post("/admissions/{$a->id}/handover", ['body' => 'nope'])->assertForbidden();
        $this->assertSame(0, Handover::count());
    }

    public function test_observer_can_read_but_not_write(): void
    {
        $owner = $this->user();
        $observer = $this->user(['role' => User::ROLE_OBSERVER]);
        $a = $this->admission(['consultant_id' => $owner->id]);
        $this->freshHandover($a, $owner, 'Visible to all roles.');

        $this->actingAs($observer)->get("/admissions/{$a->id}/handover")
            ->assertOk()
            ->assertJsonPath('body', 'Visible to all roles.')
            ->assertJsonPath('updated_by_name', $owner->name)
            ->assertJsonCount(1, 'revisions');

        $this->actingAs($observer)->post("/admissions/{$a->id}/handover", ['body' => 'x'])->assertForbidden();
    }

    public function test_outgoing_consultant_with_pending_signature_may_still_update(): void
    {
        // After a gated transfer the OLD consultant no longer owns the admission, but the inbox
        // "My outgoing" editor must let them refresh the text until the receiver signs.
        $from = $this->user();
        $to = $this->user();
        $a = $this->admission(['consultant_id' => $to->id]);   // already moved to the receiver
        $this->freshHandover($a, $from, 'v1');
        HandoverSignature::create(['admission_id' => $a->id, 'from_consultant_id' => $from->id,
            'to_consultant_id' => $to->id, 'required_at' => now()]);

        $this->actingAs($from)->post("/admissions/{$a->id}/handover", ['body' => 'v2 — late labs'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('v2 — late labs', Handover::where('admission_id', $a->id)->value('body'));
    }

    // ---- 2. same-day gate on assign ------------------------------------------------------------
    // HC-T2: the same-day handover gate on single-patient assign is now a SOFT gate (matching
    // bulkReassign, HO-T5) — a stale/missing handover no longer blocks the move. It proceeds and
    // raises a persistent `handover.incomplete` reminder to the actor and the outgoing consultant
    // instead (see tests/Feature/ReassignReminderTest.php for the dedicated reminder coverage).

    public function test_assign_to_different_consultant_proceeds_without_today_handover_and_raises_a_reminder(): void
    {
        $from = $this->user();
        $to = $this->user();
        $a = $this->admission(['consultant_id' => $from->id]);
        $admin = $this->user(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->post("/admissions/{$a->id}/assign", ['consultant_id' => $to->id])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame($to->id, (int) $a->fresh()->consultant_id, 'the move proceeds despite the missing handover');
        $this->assertSame(1, HandoverSignature::count(), 'a gated move still creates the receiver signature, same as before');
        $this->assertDatabaseHas('notifications', ['user_id' => $admin->id, 'type' => 'handover.incomplete', 'resolved_at' => null]);
        $this->assertDatabaseHas('notifications', ['user_id' => $from->id, 'type' => 'handover.incomplete', 'resolved_at' => null]);
    }

    public function test_assign_proceeds_and_raises_a_reminder_when_handover_is_stale(): void
    {
        $from = $this->user();
        $to = $this->user();
        $a = $this->admission(['consultant_id' => $from->id]);
        $this->freshHandover($a, $from);
        Handover::where('admission_id', $a->id)->update(['updated_at' => now()->subDay()]);   // yesterday

        $this->actingAs($this->user(['role' => User::ROLE_ADMIN]))
            ->post("/admissions/{$a->id}/assign", ['consultant_id' => $to->id])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($to->id, (int) $a->fresh()->consultant_id);
        // two distinct recipients here (a fresh admin actor + the outgoing consultant)
        $this->assertSame(2, Notification::where('type', 'handover.incomplete')->count());
    }

    public function test_assign_with_today_handover_creates_signature_and_notification_and_supersedes(): void
    {
        $from = $this->user(['full_name' => 'Dr From']);
        $to = $this->user();
        $a = $this->admission(['consultant_id' => $from->id]);
        $this->freshHandover($a, $from);
        $rev = HandoverRevision::where('admission_id', $a->id)->max('id');
        $prior = HandoverSignature::create(['admission_id' => $a->id, 'from_consultant_id' => $this->user()->id,
            'to_consultant_id' => $from->id, 'required_at' => now()->subDay()]);   // unsigned leftover

        $this->actingAs($this->user(['role' => User::ROLE_ADMIN]))
            ->post("/admissions/{$a->id}/assign", ['consultant_id' => $to->id])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame($to->id, (int) $a->fresh()->consultant_id);

        $sig = HandoverSignature::where('admission_id', $a->id)->whereNull('voided_at')->first();
        $this->assertNotNull($sig);
        $this->assertSame($from->id, (int) $sig->from_consultant_id);
        $this->assertSame($to->id, (int) $sig->to_consultant_id);
        $this->assertSame($rev, (int) $sig->revision_id, 'signature pins the latest revision at transfer time');
        $this->assertNotNull($sig->required_at);
        $this->assertNull($sig->signed_at);

        $this->assertNotNull($prior->fresh()->voided_at, 'prior unsigned signature is superseded');

        $n = Notification::where('user_id', $to->id)->where('type', 'handover.transfer')->first();
        $this->assertNotNull($n);
        $this->assertSame($a->id, (int) $n->payload['admission_id']);
        $this->assertSame($a->patient->name, $n->payload['patient_name']);
        $this->assertSame($a->patient->mrn, $n->payload['mrn']);
        $this->assertSame('Dr From', $n->payload['from_name']);

        $log = AuditLog::where('action', 'admission.assign')->where('entity_id', (string) $a->id)->latest('id')->first();
        $this->assertSame($sig->id, (int) ($log->details['handover_signature_id'] ?? 0), 'transfer audit links the signature');
    }

    public function test_assign_of_unassigned_admission_is_not_gated(): void
    {
        $to = $this->user();
        $a = $this->admission();   // no consultant yet — first assignment, nothing to hand over

        $this->actingAs($this->user(['role' => User::ROLE_ADMIN]))
            ->post("/admissions/{$a->id}/assign", ['consultant_id' => $to->id])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame($to->id, (int) $a->fresh()->consultant_id);
        $this->assertSame(0, HandoverSignature::count(), 'no signature for a first assignment');
        $this->assertSame(0, Notification::count());
    }

    // ---- 3. same-day gate on bulk reassign ------------------------------------------------------

    // HO-T5: the same-day handover gate on bulk reassign is now a SOFT gate — a stale/missing
    // handover no longer blocks the move. It proceeds and raises a persistent `handover.incomplete`
    // reminder to the actor and the outgoing consultant instead (see tests/Feature/ReassignReminderTest.php
    // for the dedicated coverage of the reminder's recipients/payload).
    public function test_bulk_reassign_proceeds_despite_a_missing_handover_and_raises_a_reminder(): void
    {
        $admin = $this->user(['role' => User::ROLE_ADMIN]);
        $from = $this->user();
        $to = $this->user();
        $a1 = $this->admission(['consultant_id' => $from->id]);
        $a2 = $this->admission(['consultant_id' => $from->id]);
        $this->freshHandover($a1, $from);   // a2 has none

        $this->actingAs($admin)
            ->post('/admissions/reassign', ['from_consultant_id' => $from->id, 'to_consultant_id' => $to->id])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame($to->id, (int) $a1->fresh()->consultant_id, 'move proceeds despite the stale handover on a2');
        $this->assertSame($to->id, (int) $a2->fresh()->consultant_id);

        // only a2 was stale — one reminder to the actor (admin) and one to the outgoing consultant (from)
        $this->assertSame(2, Notification::where('type', 'handover.incomplete')->count());
        $this->assertDatabaseHas('notifications', ['user_id' => $admin->id, 'type' => 'handover.incomplete']);
        $this->assertDatabaseHas('notifications', ['user_id' => $from->id, 'type' => 'handover.incomplete']);
    }

    public function test_bulk_reassign_with_today_handovers_moves_all_with_signatures_and_one_notification(): void
    {
        $from = $this->user(['full_name' => 'Dr Bulk From']);
        $to = $this->user();
        $a1 = $this->admission(['consultant_id' => $from->id]);
        $a2 = $this->admission(['consultant_id' => $from->id]);
        $this->freshHandover($a1, $from);
        $this->freshHandover($a2, $from);

        $this->actingAs($this->user(['role' => User::ROLE_ADMIN]))
            ->post('/admissions/reassign', ['from_consultant_id' => $from->id, 'to_consultant_id' => $to->id])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame($to->id, (int) $a1->fresh()->consultant_id);
        $this->assertSame($to->id, (int) $a2->fresh()->consultant_id);
        $this->assertSame(2, HandoverSignature::where('to_consultant_id', $to->id)->whereNull('voided_at')->count(),
            'one signature per moved admission');

        $n = Notification::where('user_id', $to->id)->get();
        $this->assertCount(1, $n, 'bulk move sends ONE notification');
        $this->assertSame(2, (int) $n[0]->payload['count']);
        $this->assertSame('Dr Bulk From', $n[0]->payload['from_name']);
    }

    // ---- 4. same-day gate on specialty transfer -------------------------------------------------

    // HC-T3: the same-day handover gate on specialty transfer is now a SOFT gate (matching assign,
    // HC-T2, and bulkReassign, HO-T5) — a stale/missing handover no longer blocks the move. It
    // proceeds and raises a persistent `handover.incomplete` reminder to the actor and the outgoing
    // consultant instead (see tests/Feature/ReassignReminderTest.php for the dedicated coverage).
    public function test_specialty_transfer_proceeds_without_today_handover_and_raises_a_reminder(): void
    {
        $spec = Specialty::create(['name' => 'Cardiology', 'is_subspecialty' => true]);
        $cardio = $this->user(['specialty_id' => $spec->id]);
        $from = $this->user();
        $a = $this->admission(['consultant_id' => $from->id]);
        $admin = $this->user(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->post("/admissions/{$a->id}/transfer", ['mode' => 'specialty', 'specialty_id' => $spec->id, 'consultant_id' => $cardio->id])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertNotNull($a->fresh()->discharge_date, 'the episode closes despite the missing handover');
        $this->assertSame(2, Admission::where('patient_id', $a->patient_id)->count(), 'a new episode still opens under the receiver');
        $this->assertSame(1, HandoverSignature::count(), 'a gated move still creates the receiver signature, same as before');
        $this->assertDatabaseHas('notifications', ['user_id' => $admin->id, 'type' => 'handover.incomplete', 'resolved_at' => null]);
        $this->assertDatabaseHas('notifications', ['user_id' => $from->id, 'type' => 'handover.incomplete', 'resolved_at' => null]);
    }

    public function test_specialty_transfer_with_today_handover_creates_signature_for_receiver(): void
    {
        $spec = Specialty::create(['name' => 'Cardiology', 'is_subspecialty' => true]);
        $cardio = $this->user(['specialty_id' => $spec->id]);
        $from = $this->user(['full_name' => 'Dr Spec From']);
        $a = $this->admission(['consultant_id' => $from->id]);
        $this->freshHandover($a, $from);
        $rev = HandoverRevision::where('admission_id', $a->id)->max('id');

        $this->actingAs($this->user(['role' => User::ROLE_ADMIN]))
            ->post("/admissions/{$a->id}/transfer", ['mode' => 'specialty', 'specialty_id' => $spec->id, 'consultant_id' => $cardio->id])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertNotNull($a->fresh()->discharge_date);
        $sig = HandoverSignature::where('admission_id', $a->id)->whereNull('voided_at')->first();
        $this->assertNotNull($sig, 'signature lives on the episode that carries the handover');
        $this->assertSame($from->id, (int) $sig->from_consultant_id);
        $this->assertSame($cardio->id, (int) $sig->to_consultant_id);
        $this->assertSame($rev, (int) $sig->revision_id);
        $this->assertSame(1, Notification::where('user_id', $cardio->id)->where('type', 'handover.transfer')->count());
    }

    // ---- 5. signing ----------------------------------------------------------------------------

    public function test_sign_by_receiver_binds_the_latest_revision_at_signing_time(): void
    {
        $from = $this->user();
        $to = $this->user();
        $a = $this->admission(['consultant_id' => $from->id]);
        $this->freshHandover($a, $from, 'v1');

        $this->actingAs($this->user(['role' => User::ROLE_ADMIN]))
            ->post("/admissions/{$a->id}/assign", ['consultant_id' => $to->id])->assertSessionHasNoErrors();
        $sig = HandoverSignature::where('admission_id', $a->id)->whereNull('voided_at')->first();

        // the outgoing consultant refreshes the text AFTER the transfer — receiver signs the latest
        $this->actingAs($from)->post("/admissions/{$a->id}/handover", ['body' => 'v2 — final labs'])->assertSessionHasNoErrors();
        $latest = HandoverRevision::where('admission_id', $a->id)->max('id');
        $this->assertNotSame((int) $sig->revision_id, (int) $latest);

        $this->actingAs($to)->post("/handovers/{$sig->id}/sign")->assertRedirect()->assertSessionHasNoErrors();

        $sig->refresh();
        $this->assertNotNull($sig->signed_at);
        $this->assertSame($to->id, (int) $sig->signed_by);
        $this->assertSame((int) $latest, (int) $sig->revision_id, 'signing re-binds to the CURRENT latest revision');
        $this->assertTrue(AuditLog::where('action', 'handover.sign')->where('entity_id', (string) $a->id)->exists());
    }

    public function test_sign_by_unrelated_consultant_forbidden(): void
    {
        $a = $this->admission(['consultant_id' => $this->user()->id]);
        $sig = HandoverSignature::create(['admission_id' => $a->id, 'from_consultant_id' => $this->user()->id,
            'to_consultant_id' => $this->user()->id, 'required_at' => now()]);

        $this->actingAs($this->user())->post("/handovers/{$sig->id}/sign")->assertForbidden();
        $this->assertNull($sig->fresh()->signed_at);
    }

    public function test_sign_many_signs_only_my_unsigned_signatures(): void
    {
        $me = $this->user();
        $other = $this->user();
        $a1 = $this->admission(['consultant_id' => $me->id]);
        $a2 = $this->admission(['consultant_id' => $other->id]);
        $mine = HandoverSignature::create(['admission_id' => $a1->id, 'from_consultant_id' => $other->id,
            'to_consultant_id' => $me->id, 'required_at' => now()]);
        $notMine = HandoverSignature::create(['admission_id' => $a2->id, 'from_consultant_id' => $me->id,
            'to_consultant_id' => $other->id, 'required_at' => now()]);

        $this->actingAs($me)->post('/handovers/sign-many', ['ids' => [$mine->id, $notMine->id]])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertNotNull($mine->fresh()->signed_at);
        $this->assertNull($notMine->fresh()->signed_at, 'sign-many must skip signatures addressed to others');
    }

    // ---- 6. auto-void on discharge / delete -----------------------------------------------------

    public function test_complete_discharge_voids_unsigned_signature(): void
    {
        $to = $this->user();
        $a = $this->admission(['consultant_id' => $to->id, 'medical_discharge_date' => now()->toDateString(), 'outcome' => 'Alive']);
        $sig = HandoverSignature::create(['admission_id' => $a->id, 'from_consultant_id' => $this->user()->id,
            'to_consultant_id' => $to->id, 'required_at' => now()]);

        $this->actingAs($this->user(['role' => User::ROLE_ADMIN]))
            ->post("/admissions/{$a->id}/complete-discharge", ['discharge_date' => now()->toDateString(), 'discharge_to' => 'Home'])   // destination required (N1-4)
            ->assertRedirect();

        $this->assertNotNull($a->fresh()->discharge_date);
        $this->assertNotNull($sig->fresh()->voided_at, 'discharge voids the pending signature');
    }

    public function test_icu_discharge_and_medical_discharge_with_complete_void_unsigned(): void
    {
        $admin = $this->user(['role' => User::ROLE_ADMIN]);

        $icu = $this->admission(['consultant_id' => $this->user()->id, 'current_location' => 'ICU']);
        $sigIcu = HandoverSignature::create(['admission_id' => $icu->id, 'from_consultant_id' => null,
            'to_consultant_id' => $this->user()->id, 'required_at' => now()]);
        $this->actingAs($admin)->post("/admissions/{$icu->id}/icu-discharge", [
            'outcome' => 'Alive', 'discharge_date' => now()->toDateString(), 'discharge_to' => 'Home',   // destination required (N1-4)
        ])->assertRedirect();
        $this->assertNotNull($sigIcu->fresh()->voided_at);

        $ward = $this->admission(['consultant_id' => $this->user()->id]);
        $sigWard = HandoverSignature::create(['admission_id' => $ward->id, 'from_consultant_id' => null,
            'to_consultant_id' => $this->user()->id, 'required_at' => now()]);
        $signed = HandoverSignature::create(['admission_id' => $ward->id, 'from_consultant_id' => null,
            'to_consultant_id' => $this->user()->id, 'required_at' => now(), 'signed_at' => now(), 'signed_by' => $admin->id]);
        $this->actingAs($admin)->post("/admissions/{$ward->id}/medical-discharge", [
            'outcome' => 'Alive', 'medical_discharge_date' => now()->toDateString(), 'complete' => true, 'discharge_to' => 'Home',   // destination required (N1-4)
        ])->assertRedirect();
        $this->assertNotNull($sigWard->fresh()->voided_at);
        $this->assertNull($signed->fresh()->voided_at, 'already-signed signatures are never voided');
    }

    public function test_medical_discharge_without_complete_keeps_signature_pending(): void
    {
        $a = $this->admission(['consultant_id' => $this->user()->id]);
        $sig = HandoverSignature::create(['admission_id' => $a->id, 'from_consultant_id' => null,
            'to_consultant_id' => $this->user()->id, 'required_at' => now()]);

        $this->actingAs($this->user(['role' => User::ROLE_ADMIN]))->post("/admissions/{$a->id}/medical-discharge", [
            'outcome' => 'Alive', 'medical_discharge_date' => now()->toDateString(), 'delay_reason' => 'Physical',   // medical-only needs the delay reason (N1-4)
        ])->assertRedirect();

        $this->assertNull($sig->fresh()->voided_at, 'phase-1 only: the patient is still in — signature stays');
    }

    // ---- 7. notifications -----------------------------------------------------------------------

    public function test_unread_notification_count_is_shared_with_every_inertia_page(): void
    {
        $me = $this->user();
        Notification::create(['user_id' => $me->id, 'type' => 'handover.transfer', 'payload' => ['count' => 1]]);
        Notification::create(['user_id' => $me->id, 'type' => 'handover.transfer', 'payload' => ['count' => 2], 'read_at' => now()]);
        Notification::create(['user_id' => $this->user()->id, 'type' => 'handover.transfer', 'payload' => []]);

        $this->actingAs($me)->get('/patients')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('unreadNotifications', 1));
    }

    public function test_notifications_api_lists_mine_and_read_all_clears(): void
    {
        $me = $this->user();
        Notification::create(['user_id' => $me->id, 'type' => 'handover.transfer', 'payload' => ['count' => 3, 'from_name' => 'Dr X']]);
        Notification::create(['user_id' => $this->user()->id, 'type' => 'handover.transfer', 'payload' => []]);

        $this->actingAs($me)->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('unread', 1)
            ->assertJsonCount(1, 'notifications')
            ->assertJsonPath('notifications.0.payload.from_name', 'Dr X');

        $this->actingAs($me)->postJson('/notifications/read-all')->assertOk();
        $this->assertSame(0, Notification::where('user_id', $me->id)->whereNull('read_at')->count());
    }

    /** HO-T7: read-all marks regular notifications read but must never touch an actionable
     *  (unresolved handover.incomplete) reminder — those only clear via HandoverController::save. */
    public function test_read_all_marks_transfer_read_but_leaves_the_incomplete_reminder_untouched(): void
    {
        $me = $this->user();
        $transfer = Notification::create(['user_id' => $me->id, 'type' => 'handover.transfer', 'payload' => ['count' => 1]]);
        $incomplete = Notification::create(['user_id' => $me->id, 'type' => 'handover.incomplete', 'payload' => ['admission_id' => 1]]);

        $this->actingAs($me)->postJson('/notifications/read-all')->assertOk();

        $this->assertNotNull($transfer->fresh()->read_at);
        $this->assertNull($incomplete->fresh()->read_at);
        $this->assertNull($incomplete->fresh()->resolved_at);
    }

    /** GET /api/notifications: `unread` and `actionable` are resolved-aware — an unresolved
     *  handover.incomplete counts even though it has no read_at, and drops out once resolved. */
    public function test_notifications_api_unread_and_actionable_are_resolved_aware(): void
    {
        $me = $this->user();
        Notification::create(['user_id' => $me->id, 'type' => 'handover.transfer', 'payload' => [], 'read_at' => now()]);
        $incomplete = Notification::create(['user_id' => $me->id, 'type' => 'handover.incomplete', 'payload' => ['admission_id' => 1]]);

        $this->actingAs($me)->getJson('/api/notifications')->assertOk()
            ->assertJsonPath('unread', 1)
            ->assertJsonCount(1, 'actionable')
            ->assertJsonPath('actionable.0.id', $incomplete->id);

        $incomplete->update(['resolved_at' => now()]);

        $this->actingAs($me)->getJson('/api/notifications')->assertOk()
            ->assertJsonPath('unread', 0)
            ->assertJsonCount(0, 'actionable');
    }

    /** HandleInertiaRequests' `unreadNotifications` shared prop must match /api/notifications'
     *  resolved-aware count — an unresolved handover.incomplete counts toward the bell badge. */
    public function test_shared_unread_notifications_prop_counts_an_unresolved_incomplete_reminder(): void
    {
        $me = $this->user();
        Notification::create(['user_id' => $me->id, 'type' => 'handover.incomplete', 'payload' => ['admission_id' => 1]]);

        $this->actingAs($me)->get('/patients')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('unreadNotifications', 1));
    }

    // ---- 8. inbox + preflight + board meta -------------------------------------------------------

    public function test_inbox_lists_awaiting_and_outgoing(): void
    {
        $from = $this->user(['full_name' => 'Dr Out']);
        $me = $this->user();
        $a = $this->admission(['consultant_id' => $me->id, 'bed' => 'W-7']);
        $this->freshHandover($a, $from, 'Inbox body');
        HandoverSignature::create(['admission_id' => $a->id, 'from_consultant_id' => $from->id,
            'to_consultant_id' => $me->id, 'required_at' => now()]);

        $this->actingAs($me)->get('/handovers')->assertInertia(fn (AssertableInertia $p) => $p
            ->component('Handovers/Index')
            ->has('awaiting', 1)
            ->where('awaiting.0.mrn', $a->patient->mrn)
            ->where('awaiting.0.bed', 'W-7')
            ->where('awaiting.0.from', 'Dr Out')
            ->where('awaiting.0.body', 'Inbox body')
            ->has('outgoing', 0));

        $this->actingAs($from)->get('/handovers')->assertInertia(fn (AssertableInertia $p) => $p
            ->has('awaiting', 0)
            ->has('outgoing', 1)
            ->where('outgoing.0.signed_at', null));
    }

    public function test_preflight_reports_per_admission_handover_staleness(): void
    {
        $from = $this->user();
        $fresh = $this->admission(['consultant_id' => $from->id]);
        $stale = $this->admission(['consultant_id' => $from->id]);
        $this->freshHandover($fresh, $from, 'Fresh body');
        $this->freshHandover($stale, $from, 'Old body');
        Handover::where('admission_id', $stale->id)->update(['updated_at' => now()->subDays(2)]);
        $this->admission(['consultant_id' => $from->id, 'discharge_date' => now()->toDateString()]);   // closed — excluded

        $res = $this->actingAs($this->user(['role' => User::ROLE_ADMIN]))
            ->getJson("/handovers/preflight?from_consultant_id={$from->id}")
            ->assertOk()->json();

        $this->assertCount(2, $res);
        $byId = collect($res)->keyBy('id');
        $this->assertTrue($byId[$fresh->id]['handover_today']);
        $this->assertFalse($byId[$stale->id]['handover_today']);
        $this->assertSame('Old body', $byId[$stale->id]['body']);
    }

    public function test_board_ships_handover_meta_and_sign_pending(): void
    {
        $me = $this->user();
        $a = $this->admission(['consultant_id' => $me->id]);
        $this->freshHandover($a, $me, 'Board body');
        HandoverSignature::create(['admission_id' => $a->id, 'from_consultant_id' => null,
            'to_consultant_id' => $me->id, 'required_at' => now()]);
        $bare = $this->admission(['consultant_id' => $me->id]);   // no handover, no signature

        $this->actingAs($me)->get('/patients')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('groups.0.id', $me->id)
            ->where('groups.0.patients', function ($patients) use ($a, $bare) {
                $byId = collect($patients)->keyBy('id');

                return ($byId[$a->id]['handover']['today'] ?? null) === true
                    && ($byId[$a->id]['sign_pending'] ?? null) === true
                    && $byId[$bare->id]['handover'] === null
                    && ($byId[$bare->id]['sign_pending'] ?? null) === false;
            }));
    }
}
