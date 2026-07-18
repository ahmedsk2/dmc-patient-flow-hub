<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\Handover;
use App\Models\Notification;
use App\Models\Patient;
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
            'username' => 'rr_admin_' . substr(md5(uniqid('', true)), 0, 8),
            'name' => 'RR Admin', 'password' => 'secret12345', 'role' => User::ROLE_ADMIN, 'active' => 1,
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
        ]);
        $from = User::create([
            'username' => 'rr_from_' . substr(md5(uniqid('', true)), 0, 8),
            'name' => 'RR From', 'password' => 'secret12345', 'role' => User::ROLE_CONSULTANT, 'active' => 1,
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
        ]);
        $to = User::create([
            'username' => 'rr_to_' . substr(md5(uniqid('', true)), 0, 8),
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
            'username' => 'rr_z_' . substr(md5(uniqid('', true)), 0, 8),
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

        $row = \App\Models\AuditLog::where('action', 'handover.reassign_incomplete')->latest('id')->first();
        $this->assertNotNull($row);
        $this->assertTrue((bool) ($row->details['acknowledged'] ?? false));
    }
}
