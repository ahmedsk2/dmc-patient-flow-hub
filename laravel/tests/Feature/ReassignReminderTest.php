<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\AuditLog;
use App\Models\Handover;
use App\Models\HandoverSignature;
use App\Models\Notification;
use App\Models\Patient;
use App\Models\Specialty;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * HO-T5: bulkReassign no longer blocks on a stale handover — the move proceeds and a persistent
 * `handover.incomplete` reminder is raised to the actor AND the outgoing consultant instead.
 */
class ReassignReminderTest extends TestCase
{
    use RefreshDatabase;

    private function reassignFixture(bool $withTodayHandover = false): array
    {
        $admin = User::create([
            'username' => 'rr_admin_'.substr(md5(uniqid('', true)), 0, 8),
            'name' => 'RR Admin', 'password' => 'secret12345', 'role' => User::ROLE_ADMIN, 'active' => 1,
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
        ]);
        $from = User::create([
            'username' => 'rr_from_'.substr(md5(uniqid('', true)), 0, 8),
            'name' => 'RR From', 'password' => 'secret12345', 'role' => User::ROLE_CONSULTANT, 'active' => 1,
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
        ]);
        $to = User::create([
            'username' => 'rr_to_'.substr(md5(uniqid('', true)), 0, 8),
            'name' => 'RR To', 'password' => 'secret12345', 'role' => User::ROLE_CONSULTANT, 'active' => 1,
            'on_service' => 1, 'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
        ]);

        $p = Patient::create(['mrn' => (string) random_int(10000000, 99999999), 'name' => 'RR Patient']);
        $admission = Admission::create([
            'patient_id' => $p->id, 'admit_date' => now()->subDays(2)->toDateString(),
            'current_location' => 'Ward', 'is_longterm' => 0, 'is_new_assignment' => 0,
            'consultant_id' => $from->id,
        ]);

        if ($withTodayHandover) {
            Handover::create(['admission_id' => $admission->id, 'body' => 'Stable.', 'updated_by' => $from->id]);
        }

        return [$admin, $from, $to, $admission];
    }

    public function test_reassign_proceeds_with_a_stale_handover_and_notifies_both_parties(): void
    {
        [$admin, $from, $to, $admission] = $this->reassignFixture();

        $this->actingAs($admin)->post('/admissions/reassign', [
            'from_consultant_id' => $from->id, 'to_consultant_id' => $to->id,
            'admission_ids' => [$admission->id],
        ])->assertRedirect();   // NO 422 — the move is allowed now

        $this->assertSame($to->id, (int) $admission->fresh()->consultant_id);   // moved
        $this->assertDatabaseHas('notifications', ['user_id' => $admin->id, 'type' => 'handover.incomplete', 'resolved_at' => null]);
        $this->assertDatabaseHas('notifications', ['user_id' => $from->id, 'type' => 'handover.incomplete', 'resolved_at' => null]);
    }

    public function test_no_reminder_when_the_handover_is_current(): void
    {
        [$admin, $from, $to, $admission] = $this->reassignFixture(withTodayHandover: true);
        $this->actingAs($admin)->post('/admissions/reassign', [
            'from_consultant_id' => $from->id, 'to_consultant_id' => $to->id, 'admission_ids' => [$admission->id],
        ])->assertRedirect();
        $this->assertDatabaseMissing('notifications', ['type' => 'handover.incomplete']);
    }

    public function test_saving_a_handover_resolves_the_incomplete_reminders(): void
    {
        [$admin, $from, $to, $admission] = $this->reassignFixture();
        $this->actingAs($admin)->post('/admissions/reassign', ['from_consultant_id' => $from->id, 'to_consultant_id' => $to->id, 'admission_ids' => [$admission->id]]);
        $this->assertSame(2, Notification::where('type', 'handover.incomplete')->whereNull('resolved_at')->count());

        // the receiving consultant writes the note → both reminders resolve
        $this->actingAs($to)->postJson("/admissions/{$admission->id}/handover", ['body' => 'done'])->assertOk();
        $this->assertSame(0, Notification::where('type', 'handover.incomplete')->whereNull('resolved_at')->count());
    }

    /**
     * The reminder recipients are `collect([Auth::id(), from_consultant_id])->unique()` — when the
     * acting user IS the from-consultant that collapses to ONE recipient. Locks in that a self-reassign
     * never fans out to two rows for the same person.
     */
    public function test_self_reassign_creates_only_one_reminder_not_two(): void
    {
        [, $from, $to, $admission] = $this->reassignFixture();
        $from->update(['can_manage' => true]);

        $this->actingAs($from)->post('/admissions/reassign', [
            'from_consultant_id' => $from->id, 'to_consultant_id' => $to->id,
            'admission_ids' => [$admission->id],
        ])->assertRedirect();

        $this->assertSame($to->id, (int) $admission->fresh()->consultant_id);
        $this->assertSame(1, Notification::where('type', 'handover.incomplete')->count(),
            'actor == from-consultant must collapse to a single recipient, not one row per role');
        $this->assertSame(1, Notification::where('type', 'handover.incomplete')
            ->whereNull('resolved_at')->where('user_id', $from->id)->count());
    }

    /**
     * Fix 1: reassigning the SAME still-stale admission twice (X→Y, then Y→Z) must not pile up a
     * second unresolved reminder for a recipient who already holds one for that admission.
     */
    public function test_repeat_reassign_of_a_still_stale_admission_dedups_the_admins_reminder(): void
    {
        [$admin, $x, $y, $admission] = $this->reassignFixture();
        $z = User::create([
            'username' => 'rr_z_'.substr(md5(uniqid('', true)), 0, 8),
            'name' => 'RR Z', 'password' => 'secret12345', 'role' => User::ROLE_CONSULTANT, 'active' => 1,
            'on_service' => 1, 'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
        ]);

        $this->actingAs($admin)->post('/admissions/reassign', [
            'from_consultant_id' => $x->id, 'to_consultant_id' => $y->id, 'admission_ids' => [$admission->id],
        ])->assertRedirect();
        $this->actingAs($admin)->post('/admissions/reassign', [
            'from_consultant_id' => $y->id, 'to_consultant_id' => $z->id, 'admission_ids' => [$admission->id],
        ])->assertRedirect();

        $this->assertSame($z->id, (int) $admission->fresh()->consultant_id);

        $forAdmission = fn ($userId) => Notification::where('type', 'handover.incomplete')
            ->whereNull('resolved_at')->where('user_id', $userId)
            ->where('payload->admission_id', (string) $admission->id)->count();

        $this->assertSame(1, $forAdmission($admin->id), 'admin was a recipient on both reassigns — must dedup to 1');
        $this->assertLessThanOrEqual(1, $forAdmission($x->id));
        $this->assertLessThanOrEqual(1, $forAdmission($y->id));
    }

    // ---- HC-T2: single-patient assign now uses the same soft gate as bulkReassign ----------------

    public function test_single_assign_to_a_different_consultant_proceeds_with_a_stale_handover(): void
    {
        [$admin, $from, $to, $admission] = $this->reassignFixture();

        $this->actingAs($admin)->post("/admissions/{$admission->id}/assign", [
            'consultant_id' => $to->id,
        ])->assertRedirect()->assertSessionHasNoErrors();   // NO 422 — the hard gate is gone

        $this->assertSame($to->id, (int) $admission->fresh()->consultant_id);
        $this->assertDatabaseHas('notifications', ['user_id' => $admin->id, 'type' => 'handover.incomplete', 'resolved_at' => null]);
        $this->assertDatabaseHas('notifications', ['user_id' => $from->id, 'type' => 'handover.incomplete', 'resolved_at' => null]);
    }

    public function test_single_assign_records_the_acknowledgement_in_the_audit_trail(): void
    {
        [$admin, $from, $to, $admission] = $this->reassignFixture();

        $this->actingAs($admin)->post("/admissions/{$admission->id}/assign", [
            'consultant_id' => $to->id, 'acknowledged' => true,
        ])->assertRedirect();

        $row = AuditLog::where('action', 'handover.reassign_incomplete')->latest('id')->first();
        $this->assertNotNull($row);
        $this->assertTrue((bool) ($row->details['acknowledged'] ?? false));
    }

    // ---- HC-T3: specialty transfer now uses the same soft gate ------------------------------------

    public function test_specialty_transfer_proceeds_with_a_stale_handover_and_notifies(): void
    {
        [$admin, $from, $to, $admission] = $this->reassignFixture();
        $spec = Specialty::create(['name' => 'Cardiology', 'is_subspecialty' => true]);
        $to->forceFill(['specialty_id' => $spec->id])->save();

        $this->actingAs($admin)->post("/admissions/{$admission->id}/transfer", [
            'mode' => 'specialty', 'specialty_id' => $spec->id, 'consultant_id' => $to->id,
        ])->assertRedirect()->assertSessionHasNoErrors();   // NO 422 — the hard gate is gone

        $this->assertNotNull($admission->fresh()->discharge_date, 'the outgoing episode must close');
        $newAdmission = Admission::where('patient_id', $admission->patient_id)->where('id', '!=', $admission->id)->first();
        $this->assertNotNull($newAdmission, 'a new episode opens under the receiving consultant');
        $this->assertSame($to->id, (int) $newAdmission->consultant_id);

        $this->assertDatabaseHas('notifications', ['user_id' => $from->id, 'type' => 'handover.incomplete', 'resolved_at' => null]);
        $this->assertDatabaseHas('notifications', ['user_id' => $admin->id, 'type' => 'handover.incomplete', 'resolved_at' => null]);
    }

    /**
     * The closing episode is discharged and drops off the board/inbox (Admission::active() and
     * scopeHandoverPending() both exclude it), so a reminder anchored there is a dead end after
     * the outgoing consultant's 7-day "My outgoing" window lapses. The reminder must target the
     * NEW active episode instead — that's the one clinicians can actually see and act on.
     */
    public function test_specialty_transfer_reminder_targets_the_new_active_episode_not_the_closed_one(): void
    {
        [$admin, $from, $to, $admission] = $this->reassignFixture();
        $spec = Specialty::create(['name' => 'Cardiology', 'is_subspecialty' => true]);
        $to->forceFill(['specialty_id' => $spec->id])->save();

        $this->actingAs($admin)->post("/admissions/{$admission->id}/transfer", [
            'mode' => 'specialty', 'specialty_id' => $spec->id, 'consultant_id' => $to->id,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $closed = $admission->fresh();
        $new = Admission::where('patient_id', $admission->patient_id)->where('id', '!=', $admission->id)->firstOrFail();

        $this->assertNotNull($closed->discharge_date, 'the outgoing episode must close');
        $this->assertNull($new->discharge_date, 'the new episode must be active so the board/inbox can surface it');

        $reminders = Notification::where('type', 'handover.incomplete')->whereNull('resolved_at')->get();
        $this->assertNotEmpty($reminders);
        foreach ($reminders as $n) {
            $this->assertSame((string) $new->id, (string) data_get($n->payload, 'admission_id'),
                'every handover.incomplete reminder must point at the new active episode');
            $this->assertNotSame((string) $closed->id, (string) data_get($n->payload, 'admission_id'),
                'no reminder may reference the closed episode — it is unreachable from the board/inbox');
        }
    }

    /** End-to-end proof the reminder isn't a dead end: the receiving consultant can resolve it. */
    public function test_saving_a_handover_on_the_new_episode_resolves_the_specialty_transfer_reminders(): void
    {
        [$admin, $from, $to, $admission] = $this->reassignFixture();
        $spec = Specialty::create(['name' => 'Cardiology', 'is_subspecialty' => true]);
        $to->forceFill(['specialty_id' => $spec->id])->save();

        $this->actingAs($admin)->post("/admissions/{$admission->id}/transfer", [
            'mode' => 'specialty', 'specialty_id' => $spec->id, 'consultant_id' => $to->id,
        ])->assertRedirect();

        $new = Admission::where('patient_id', $admission->patient_id)->where('id', '!=', $admission->id)->firstOrFail();
        $this->assertGreaterThan(0, Notification::where('type', 'handover.incomplete')->whereNull('resolved_at')
            ->where('payload->admission_id', (string) $new->id)->count());

        $this->actingAs($to)->postJson("/admissions/{$new->id}/handover", ['body' => 'Handover reviewed.'])->assertOk();

        $this->assertSame(0, Notification::where('type', 'handover.incomplete')->whereNull('resolved_at')
            ->where('payload->admission_id', (string) $new->id)->count(),
            'saving a handover note on the new episode must resolve its reminders for every recipient');
    }

    public function test_the_receiving_consultant_never_gets_the_pending_alarm(): void
    {
        [$admin, $from, $to, $admission] = $this->reassignFixture();

        $this->actingAs($admin)->post('/admissions/reassign', [
            'from_consultant_id' => $from->id, 'to_consultant_id' => $to->id, 'admission_ids' => [$admission->id],
        ])->assertRedirect();

        // initiator + OUTGOING consultant are chased …
        $this->assertDatabaseHas('notifications', ['user_id' => $admin->id, 'type' => 'handover.incomplete']);
        $this->assertDatabaseHas('notifications', ['user_id' => $from->id, 'type' => 'handover.incomplete']);
        // … the RECEIVER is not (they get the ordinary handover.transfer notice instead)
        $this->assertDatabaseMissing('notifications', ['user_id' => $to->id, 'type' => 'handover.incomplete']);
        $this->assertDatabaseHas('notifications', ['user_id' => $to->id, 'type' => 'handover.transfer']);
    }

    // ---- E2 (role walkthrough 2026-09-25): the outgoing consultant's "My outgoing" save must
    // resolve the NEW episode's reminder too, not just the closed one it can actually write to ----

    /**
     * Reproduces admin-clinical.md finding #1: the outgoing consultant's HandoverSignature — and
     * therefore their only write access — is on the CLOSING (old) episode, while the
     * `handover.incomplete` reminder that "Needs handover" / the bell track is on the NEW episode.
     * Saving on the old episode (POST /admissions/{old}/handover, exactly what "My outgoing" →
     * "Update text" does) must now resolve the new episode's reminder for every recipient, drop the
     * patient out of every "Needs handover" count, and leave the receiving consultant still needing
     * to sign.
     */
    public function test_saving_on_the_old_closing_episode_resolves_the_new_episodes_reminder(): void
    {
        [$admin, $from, $to, $admission] = $this->reassignFixture();
        $spec = Specialty::create(['name' => 'Cardiology', 'is_subspecialty' => true]);
        $to->forceFill(['specialty_id' => $spec->id])->save();

        $this->actingAs($admin)->post("/admissions/{$admission->id}/transfer", [
            'mode' => 'specialty', 'specialty_id' => $spec->id, 'consultant_id' => $to->id,
        ])->assertRedirect();

        $old = $admission->fresh();
        $new = Admission::where('patient_id', $admission->patient_id)->where('id', '!=', $old->id)->firstOrFail();
        $this->assertSame($old->id, $new->predecessor_admission_id, 'the new episode must record its predecessor');
        $this->assertGreaterThan(0, Notification::where('type', 'handover.incomplete')->whereNull('resolved_at')
            ->where('admission_id', $new->id)->count());
        $this->assertSame(1, Admission::handoverPending()->where('id', $new->id)->count(),
            'the new episode starts out under "Needs handover"');

        // the outgoing consultant's ONLY write route: "My outgoing" posts to the OLD episode
        $this->actingAs($from)->postJson("/admissions/{$old->id}/handover", ['body' => 'Stable overnight, cardiology aware.'])
            ->assertOk();

        $this->assertSame(0, Notification::where('type', 'handover.incomplete')->whereNull('resolved_at')
            ->where('admission_id', $new->id)->count(),
            'the new episode reminder must resolve for every recipient, not just the closed one');
        $this->assertSame(0, Admission::handoverPending()->where('id', $new->id)->count(),
            'the new episode must drop out of "Needs handover" — DashboardController::handoverDue and '.
            'PatientsController::needsHandoverCount both key off this exact scope, so this one assertion '.
            'proves every consumer moved together');

        // the receiving consultant's signature is UNTOUCHED — they still must read + sign
        $sig = HandoverSignature::where('admission_id', $old->id)->latest('id')->first();
        $this->assertNotNull($sig);
        $this->assertNull($sig->signed_at, 'writing the outgoing note must never auto-sign the receiver\'s acknowledgement');
    }

    /** Saving on some unrelated closed episode (no predecessor link to anything) resolves nothing. */
    public function test_saving_on_an_unrelated_closed_episode_resolves_nothing(): void
    {
        [$admin, $from, $to, $admission] = $this->reassignFixture();
        $spec = Specialty::create(['name' => 'Cardiology', 'is_subspecialty' => true]);
        $to->forceFill(['specialty_id' => $spec->id])->save();

        $this->actingAs($admin)->post("/admissions/{$admission->id}/transfer", [
            'mode' => 'specialty', 'specialty_id' => $spec->id, 'consultant_id' => $to->id,
        ])->assertRedirect();
        $new = Admission::where('patient_id', $admission->patient_id)->where('id', '!=', $admission->id)->firstOrFail();

        // an unrelated, never-transferred, already-discharged admission for a different patient
        $p2 = Patient::create(['mrn' => (string) random_int(10000000, 99999999), 'name' => 'RR Other Patient']);
        $unrelated = Admission::create([
            'patient_id' => $p2->id, 'admit_date' => now()->subDays(5)->toDateString(),
            'discharge_date' => now()->subDay()->toDateString(), 'current_location' => 'Ward',
            'is_longterm' => 0, 'is_new_assignment' => 0, 'consultant_id' => $from->id,
        ]);
        $this->assertNull($unrelated->predecessor_admission_id);

        $before = Notification::where('type', 'handover.incomplete')->whereNull('resolved_at')->count();
        $this->actingAs($admin)->postJson("/admissions/{$unrelated->id}/handover", ['body' => 'unrelated note'])
            ->assertOk();

        $this->assertSame($before, Notification::where('type', 'handover.incomplete')->whereNull('resolved_at')->count(),
            'an admission with no successor must resolve nothing beyond its own (empty) reminders');
        $this->assertGreaterThan(0, Notification::where('type', 'handover.incomplete')->whereNull('resolved_at')
            ->where('admission_id', $new->id)->count(), 'the real transfer reminder must be untouched');
    }

    /** A same-episode assign (no episode split) behaves exactly as before this fix. */
    public function test_same_episode_assign_still_resolves_only_its_own_reminder(): void
    {
        [$admin, $from, $to, $admission] = $this->reassignFixture();
        $this->actingAs($admin)->post("/admissions/{$admission->id}/assign", ['consultant_id' => $to->id])
            ->assertRedirect();
        $this->assertNull($admission->fresh()->predecessor_admission_id);

        $this->actingAs($to)->postJson("/admissions/{$admission->id}/handover", ['body' => 'seen'])->assertOk();
        $this->assertSame(0, Notification::where('type', 'handover.incomplete')->whereNull('resolved_at')->count());
    }

    // ---- E2 fix-up (role walkthrough 2026-09-25, adversarial review): predecessor_admission_id
    // carries no uniqueness guarantee, so more than one admission can point back at the same
    // predecessor — the resolution must not silently strand whichever one it didn't pick ----

    /**
     * A same-day admin reverseDischarge reopens the closing episode without voiding the successor
     * transferSpecialty already created from it (reverseDischarge is not owned by this group — see
     * the report's NEEDS ANOTHER GROUP). A second, legitimate transferSpecialty of the reopened
     * episode then creates a SECOND successor with the same predecessor_admission_id. Both must
     * still resolve when the outgoing consultant saves on the shared old episode — a naive
     * single-row ->value('id') pick would leave whichever one it didn't choose stuck under "Needs
     * handover" forever.
     */
    public function test_two_successors_from_the_same_predecessor_both_resolve(): void
    {
        [$admin, $from, $to, $admission] = $this->reassignFixture();
        $spec = Specialty::create(['name' => 'Cardiology', 'is_subspecialty' => true]);
        $to->forceFill(['specialty_id' => $spec->id])->save();

        $this->actingAs($admin)->post("/admissions/{$admission->id}/transfer", [
            'mode' => 'specialty', 'specialty_id' => $spec->id, 'consultant_id' => $to->id,
        ])->assertRedirect();
        $old = $admission->fresh();
        $first = Admission::where('predecessor_admission_id', $old->id)->firstOrFail();

        $this->actingAs($admin)->withSession(['stepup.verified_at' => now()->getTimestamp()])
            ->post("/admissions/{$old->id}/reverse-discharge")->assertRedirect();
        $this->assertNull($old->fresh()->discharge_date, 'the reopened episode must be active again for a second transfer');

        $this->actingAs($admin)->post("/admissions/{$old->id}/transfer", [
            'mode' => 'specialty', 'specialty_id' => $spec->id, 'consultant_id' => $to->id,
        ])->assertRedirect();
        $second = Admission::where('predecessor_admission_id', $old->id)->where('id', '!=', $first->id)->firstOrFail();

        $this->assertSame(2, Admission::where('predecessor_admission_id', $old->id)->count(),
            'both successors must share the same predecessor for this to be a real test of the double-successor case');
        $this->assertGreaterThan(0, Notification::where('type', 'handover.incomplete')->whereNull('resolved_at')
            ->where('admission_id', $first->id)->count());
        $this->assertGreaterThan(0, Notification::where('type', 'handover.incomplete')->whereNull('resolved_at')
            ->where('admission_id', $second->id)->count());

        // the outgoing consultant's single save on the shared old episode
        $this->actingAs($from)->postJson("/admissions/{$old->id}/handover", ['body' => 'Both moves reviewed.'])
            ->assertOk();

        $this->assertSame(0, Notification::where('type', 'handover.incomplete')->whereNull('resolved_at')
            ->where('admission_id', $first->id)->count(), 'the FIRST successor must resolve too, not just the last one a single-row pick would find');
        $this->assertSame(0, Notification::where('type', 'handover.incomplete')->whereNull('resolved_at')
            ->where('admission_id', $second->id)->count());
    }

    /**
     * The window transfer()'s pre-transaction discharge_date guard cannot close: two requests that
     * both read discharge_date as null before either has committed. Simulates the second, "already
     * committed" transfer landing between this request's Specialty lookup and the new lockForUpdate
     * re-check inside transferSpecialty's transaction (same technique as
     * AdmissionDoubleSubmitTest::test_transaction_level_lock_blocks_an_episode_created_mid_request).
     */
    public function test_a_concurrent_second_transfer_is_rejected_not_duplicated(): void
    {
        [$admin, $from, $to, $admission] = $this->reassignFixture();
        $spec = Specialty::create(['name' => 'Cardiology', 'is_subspecialty' => true]);
        $to->forceFill(['specialty_id' => $spec->id])->save();

        $fired = false;
        Specialty::retrieved(function (Specialty $s) use ($spec, $admission, $to, &$fired) {
            if ($fired || $s->id !== $spec->id) {
                return;
            }
            $fired = true;
            // the "other request" — already committed by the time this one takes its lock
            Admission::whereKey($admission->id)->update([
                'discharge_date' => now()->toDateString(), 'transfer_type' => 'transfer to other speciality',
            ]);
            Admission::create([
                'patient_id' => $admission->patient_id, 'admit_date' => now()->toDateString(),
                'current_location' => 'Ward', 'consultant_id' => $to->id, 'is_longterm' => 0,
                'is_new_assignment' => 1, 'predecessor_admission_id' => $admission->id,
            ]);
        });

        $this->actingAs($admin)->post("/admissions/{$admission->id}/transfer", [
            'mode' => 'specialty', 'specialty_id' => $spec->id, 'consultant_id' => $to->id,
        ])->assertSessionHasErrors(['consultant_id' => 'This admission was just transferred by another request.']);

        $this->assertTrue($fired, 'the simulated concurrent transfer never ran — the race was not exercised');
        $this->assertSame(1, Admission::where('predecessor_admission_id', $admission->id)->count(),
            'the raced request must not create a second successor');
    }

    public function test_reminders_are_written_with_the_admission_id_column_and_resolve_by_it(): void
    {
        [$admin, $from, $to, $admission] = $this->reassignFixture();

        $this->actingAs($admin)->post('/admissions/reassign', [
            'from_consultant_id' => $from->id, 'to_consultant_id' => $to->id, 'admission_ids' => [$admission->id],
        ])->assertRedirect();

        // every reminder carries the column, not just the JSON payload
        $this->assertSame(2, Notification::where('type', 'handover.incomplete')
            ->where('admission_id', $admission->id)->whereNull('resolved_at')->count());

        // saving the note resolves them THROUGH the column
        $this->actingAs($to)->postJson("/admissions/{$admission->id}/handover", ['body' => 'done'])->assertOk();
        $this->assertSame(0, Notification::where('type', 'handover.incomplete')
            ->where('admission_id', $admission->id)->whereNull('resolved_at')->count());
    }
}
