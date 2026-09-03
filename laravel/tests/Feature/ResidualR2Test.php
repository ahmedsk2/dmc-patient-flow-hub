<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\AuditLog;
use App\Models\Consultation;
use App\Models\Handover;
use App\Models\Icd10;
use App\Models\Patient;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Residual batch R2 — workflow:
 *  1. board cards ship diagnosis NAMES (one icd10 lookup), not just a count
 *  2. Recent discharges registry gains admit date / LOS / diagnoses / destination / admitter
 *  3. Control user management: full_name+email edit, deactivation/delete guards, admin delete + audit
 *  4. inline bed edit endpoint (canManage-gated, audited with the old value)
 *  5. mark-as-new toggle on assign + bulk reassign (quiet administrative assignment)
 *  6. board summary counts gain 'old' (= total - new)
 *  7. dashboard: per-consultant last-24h activity + YTD counter strip
 */
class ResidualR2Test extends TestCase
{
    use RefreshDatabase;

    private function user(int $role, array $extra = []): User
    {
        return User::create(array_merge([
            'username' => 'r2_'.substr(md5(uniqid('', true)), 0, 10),
            'name' => 'R2 User', 'password' => 'secret12345', 'role' => $role, 'active' => 1,
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
        ], $extra));
    }

    private function admin(): User
    {
        return $this->user(User::ROLE_ADMIN);
    }

    private function admission(array $overrides = [], ?Patient $patient = null): Admission
    {
        $p = $patient ?? Patient::create(['mrn' => (string) random_int(10000000, 99999999), 'name' => 'R2 Patient']);

        return Admission::create(array_merge([
            'patient_id' => $p->id, 'admit_date' => now()->subDays(3)->toDateString(),
            'current_location' => 'Ward', 'is_longterm' => 0, 'is_new_assignment' => 0,
        ], $overrides));
    }

    // ---- 1 + 6. board: diagnosis names + 'old' count ------------------------------------------

    public function test_board_ships_diagnosis_names_and_old_count(): void
    {
        Icd10::create(['code' => 'J18.9', 'name' => 'Pneumonia, unspecified organism']);
        Icd10::create(['code' => 'E11.9', 'name' => 'Type 2 diabetes mellitus without complications']);
        $c = $this->user(User::ROLE_CONSULTANT, ['full_name' => 'Dr Board Dx']);

        // patient A: flagged New (is_new_assignment) with two diagnoses; B: not flagged (Old), no dx
        $a = $this->admission(['admit_date' => now()->subDays(5)->toDateString(),
            'consultant_id' => $c->id, 'is_new_assignment' => 1]);
        $a->diagnoses()->create(['seq' => 1, 'icd10_code' => 'J18.9']);
        $a->diagnoses()->create(['seq' => 2, 'icd10_code' => 'E11.9']);
        $this->admission(['admit_date' => now()->subDays(3)->toDateString(), 'consultant_id' => $c->id]);

        $this->actingAs($this->admin())->get('/patients')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('groups.0.patients.0.diagnoses', [
                    ['code' => 'J18.9', 'name' => 'Pneumonia, unspecified organism'],
                    ['code' => 'E11.9', 'name' => 'Type 2 diabetes mellitus without complications'],
                ])
                ->where('groups.0.patients.1.diagnoses', [])
                ->where('groups.0.counts.new', 1)
                ->where('groups.0.counts.old', 1)
                ->where('groups.0.counts.total', 2));
    }

    public function test_board_diagnosis_name_falls_back_to_code_when_unknown(): void
    {
        $c = $this->user(User::ROLE_CONSULTANT, ['full_name' => 'Dr Fallback']);
        $a = $this->admission(['consultant_id' => $c->id]);
        $a->diagnoses()->create(['seq' => 1, 'icd10_code' => 'Z99.X']);   // not in the icd10 table

        $this->actingAs($this->admin())->get('/patients')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('groups.0.patients.0.diagnoses.0', ['code' => 'Z99.X', 'name' => 'Z99.X']));
    }

    // ---- 2. recent discharges registry columns ------------------------------------------------

    public function test_recent_discharges_carry_admit_date_los_diagnoses_destination_and_admitter(): void
    {
        Icd10::create(['code' => 'J18.9', 'name' => 'Pneumonia, unspecified organism']);
        Icd10::create(['code' => 'E11.9', 'name' => 'Type 2 diabetes mellitus without complications']);
        $admitter = $this->user(User::ROLE_REGISTRAR, ['full_name' => 'Dr Admitter']);
        $admit = now()->subDays(6)->toDateString();
        $discharge = now()->subDay()->toDateString();
        $a = $this->admission([
            'admit_date' => $admit, 'discharge_date' => $discharge, 'outcome' => 'Alive',
            'transfer_type' => 'discharge from ward', 'discharge_to' => 'Home', 'admitted_by' => $admitter->id,
        ]);
        $a->diagnoses()->create(['seq' => 1, 'icd10_code' => 'J18.9']);
        $a->diagnoses()->create(['seq' => 2, 'icd10_code' => 'E11.9']);

        $this->actingAs($this->admin())->get('/recent')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('discharges.0.admit_date', $admit)
                ->where('discharges.0.los', 5)
                ->where('discharges.0.diagnoses', 'Pneumonia, unspecified organism; Type 2 diabetes mellitus without complications')
                ->where('discharges.0.discharge_to', 'Home')
                ->where('discharges.0.admitter', 'Dr Admitter'));
    }

    // ---- 3. control user management -------------------------------------------------------------

    private function userPayload(User $u, array $overrides = []): array
    {
        return array_merge([
            'username' => $u->username,   // required since R3 (admin username edit)
            'role' => (int) $u->role, 'active' => true, 'on_service' => false, 'specialty_id' => null,
            'can_assign' => false, 'can_add' => false, 'can_manage' => false, 'can_modify' => false,
        ], $overrides);
    }

    public function test_update_user_persists_full_name_and_email(): void
    {
        $u = $this->user(User::ROLE_RESIDENT);

        $this->actingAs($this->admin())->put("/control/users/{$u->id}", $this->userPayload($u, [
            'full_name' => 'Renamed Resident', 'email' => 'renamed@example.test',
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $u->refresh();
        $this->assertSame('Renamed Resident', $u->full_name);
        $this->assertSame('renamed@example.test', $u->email);
    }

    public function test_update_user_email_must_be_unique_ignoring_self(): void
    {
        $other = $this->user(User::ROLE_RESIDENT, ['email' => 'taken@example.test']);
        $u = $this->user(User::ROLE_RESIDENT, ['email' => 'mine@example.test']);

        $this->actingAs($this->admin())->from('/control')->put("/control/users/{$u->id}",
            $this->userPayload($u, ['email' => 'taken@example.test']))
            ->assertRedirect('/control')->assertSessionHasErrors('email');
        $this->assertSame('mine@example.test', $u->fresh()->email);

        // keeping one's OWN email is not a collision
        $this->actingAs($this->admin())->put("/control/users/{$u->id}",
            $this->userPayload($u, ['email' => 'mine@example.test']))
            ->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_deactivation_blocked_while_user_has_active_admissions(): void
    {
        $c = $this->user(User::ROLE_CONSULTANT);
        $this->admission(['consultant_id' => $c->id]);   // active (discharge_date NULL)

        $this->actingAs($this->admin())->put("/control/users/{$c->id}", $this->userPayload($c, ['active' => false]))
            ->assertRedirect()->assertSessionHas('flash.type', 'error');
        $this->assertTrue($c->fresh()->active, 'a consultant with active patients must not be deactivated');

        // once the patient is discharged, deactivation goes through
        Admission::where('consultant_id', $c->id)->update([
            'discharge_date' => now()->toDateString(), 'transfer_type' => 'discharge from ward', 'outcome' => 'Alive']);
        $this->actingAs($this->admin())->put("/control/users/{$c->id}", $this->userPayload($c, ['active' => false]))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertFalse($c->fresh()->active);
    }

    public function test_self_delete_is_blocked(): void
    {
        $admin = $this->admin();

        // Phase 4 — Item 4: user delete is step-up gated; provide a fresh step-up so the controller's
        // self-delete guard (not the gate) is what blocks it
        $this->actingAs($admin)->withSession(['stepup.verified_at' => now()->getTimestamp()])
            ->delete("/control/users/{$admin->id}")
            ->assertRedirect()->assertSessionHas('flash.type', 'error');
        $this->assertNotNull(User::find($admin->id));
    }

    public function test_delete_blocked_while_user_has_active_admissions(): void
    {
        $c = $this->user(User::ROLE_CONSULTANT);
        $this->admission(['consultant_id' => $c->id]);

        $this->actingAs($this->admin())->withSession(['stepup.verified_at' => now()->getTimestamp()])
            ->delete("/control/users/{$c->id}")
            ->assertRedirect()->assertSessionHas('flash.type', 'error');
        $this->assertNotNull(User::find($c->id));
    }

    public function test_delete_clean_user_works_and_is_audited(): void
    {
        $u = $this->user(User::ROLE_RESIDENT, ['full_name' => 'Dr Deletable']);
        $username = $u->username;
        // a DISCHARGED admission referencing the user must not block the delete
        $a = $this->admission(['consultant_id' => $u->id, 'discharge_date' => now()->subDay()->toDateString(),
            'transfer_type' => 'discharge from ward', 'outcome' => 'Alive']);

        $this->actingAs($this->admin())->withSession(['stepup.verified_at' => now()->getTimestamp()])
            ->delete("/control/users/{$u->id}")
            ->assertRedirect()->assertSessionHasNoErrors();

        // Phase 4 — Item 1: user delete is now a SOFT delete — the global scope hides the user, but
        // the row survives so the nullOnDelete FK never fires and historical attribution is KEPT
        // (the raw per-consultant joins still resolve the name).
        $this->assertNull(User::find($u->id));
        $this->assertNotNull(User::withTrashed()->find($u->id)->deleted_at);
        $this->assertSame($u->id, (int) $a->fresh()->consultant_id, 'historical attribution is preserved across a soft-delete');
        $audit = AuditLog::where('action', 'user.delete')->where('entity_id', (string) $u->id)->first();
        $this->assertNotNull($audit, 'user deletion must be audited');
        $this->assertSame($username, $audit->details['username'] ?? null);
    }

    public function test_delete_user_is_admin_only(): void
    {
        $victim = $this->user(User::ROLE_RESIDENT);

        $this->actingAs($this->user(User::ROLE_REGISTRAR))->delete("/control/users/{$victim->id}")->assertForbidden();
        $this->assertNotNull(User::find($victim->id));
    }

    // ---- 4. inline bed edit ---------------------------------------------------------------------

    public function test_owner_consultant_updates_bed_and_old_value_is_audited(): void
    {
        $owner = $this->user(User::ROLE_CONSULTANT);
        $a = $this->admission(['consultant_id' => $owner->id, 'bed' => 'A-01']);

        $this->actingAs($owner)->post("/admissions/{$a->id}/bed", ['bed' => 'B-12'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('B-12', $a->fresh()->bed);
        $audit = AuditLog::where('action', 'admission.bed')->where('entity_id', (string) $a->id)->first();
        $this->assertNotNull($audit);
        $this->assertSame('A-01', $audit->details['was'] ?? null);
        $this->assertSame('B-12', $audit->details['bed'] ?? null);
    }

    public function test_bed_update_rejected_for_observer_but_open_to_clinical_roles(): void
    {
        $owner = $this->user(User::ROLE_CONSULTANT);
        $a = $this->admission(['consultant_id' => $owner->id, 'bed' => 'A-01']);

        $this->actingAs($this->user(User::ROLE_OBSERVER))->post("/admissions/{$a->id}/bed", ['bed' => 'X'])->assertForbidden();
        $this->assertSame('A-01', $a->fresh()->bed);

        // J1-11 (deliberate change): ANY clinical role may inline-edit the bed, like the legacy
        // inline edits (was canManage) — a non-owner consultant is no longer rejected
        $this->actingAs($this->user(User::ROLE_CONSULTANT))
            ->post("/admissions/{$a->id}/bed", ['bed' => 'X'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('X', $a->fresh()->bed);

        // a Manage-capability user keeps working too
        $this->actingAs($this->user(User::ROLE_REGISTRAR, ['can_manage' => true]))
            ->post("/admissions/{$a->id}/bed", ['bed' => null])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull($a->fresh()->bed);
    }

    // ---- 5. mark-as-new toggle --------------------------------------------------------------------

    public function test_assign_with_mark_new_false_skips_the_new_assignment_fields(): void
    {
        $c = $this->user(User::ROLE_CONSULTANT);
        $a = $this->admission();   // unassigned

        $this->actingAs($this->admin())->post("/admissions/{$a->id}/assign", [
            'consultant_id' => $c->id, 'mark_new' => false,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $a->refresh();
        $this->assertSame($c->id, (int) $a->consultant_id);
        $this->assertFalse((bool) $a->is_new_assignment);
        $this->assertNull($a->assigned_at, 'quiet assignment must not stamp assigned_at');
        $this->assertNull($a->assigned_on);
    }

    public function test_assign_defaults_to_mark_new_true_when_absent(): void
    {
        $c = $this->user(User::ROLE_CONSULTANT);
        $a = $this->admission();

        $this->actingAs($this->admin())->post("/admissions/{$a->id}/assign", ['consultant_id' => $c->id])
            ->assertRedirect()->assertSessionHasNoErrors();

        $a->refresh();
        $this->assertTrue((bool) $a->is_new_assignment);
        $this->assertNotNull($a->assigned_at);
    }

    public function test_bulk_reassign_with_mark_new_false_preserves_existing_assigned_at(): void
    {
        $from = $this->user(User::ROLE_CONSULTANT);
        $to = $this->user(User::ROLE_CONSULTANT);
        $stamp = now()->subDays(3)->startOfSecond();
        $a = $this->admission(['consultant_id' => $from->id, 'assigned_at' => $stamp,
            'assigned_on' => $stamp->toDateString(), 'is_new_assignment' => 1]);
        // bulk reassign is handover-gated: every moving patient needs a handover updated TODAY
        Handover::create(['admission_id' => $a->id, 'body' => 'Plan attached.', 'updated_by' => $from->id]);

        $this->actingAs($this->admin())->post('/admissions/reassign', [
            'from_consultant_id' => $from->id, 'to_consultant_id' => $to->id, 'mark_new' => false,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $a->refresh();
        $this->assertSame($to->id, (int) $a->consultant_id);
        $this->assertSame($stamp->toDateTimeString(), $a->assigned_at->toDateTimeString(),
            'mark_new=false must PRESERVE the existing assigned_at, not null or refresh it');
    }

    public function test_bulk_reassign_default_still_stamps_new_assignment(): void
    {
        $from = $this->user(User::ROLE_CONSULTANT);
        $to = $this->user(User::ROLE_CONSULTANT);
        $a = $this->admission(['consultant_id' => $from->id]);
        // bulk reassign is handover-gated: every moving patient needs a handover updated TODAY
        Handover::create(['admission_id' => $a->id, 'body' => 'Plan attached.', 'updated_by' => $from->id]);

        $this->actingAs($this->admin())->post('/admissions/reassign', [
            'from_consultant_id' => $from->id, 'to_consultant_id' => $to->id,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $a->refresh();
        $this->assertSame($to->id, (int) $a->consultant_id);
        $this->assertTrue((bool) $a->is_new_assignment);
        $this->assertNotNull($a->assigned_at);
    }

    // ---- 7. dashboard: 24h activity + YTD strip ----------------------------------------------------

    public function test_dashboard_activity24h_counts_per_consultant_since_yesterday(): void
    {
        $one = $this->user(User::ROLE_CONSULTANT, ['full_name' => 'Dr Day One']);
        $two = $this->user(User::ROLE_CONSULTANT, ['full_name' => 'Dr Day Two']);
        $idle = $this->user(User::ROLE_CONSULTANT, ['full_name' => 'Dr Idle']);

        // Dr Day One: one admission today (+ an old one that must NOT count)
        $this->admission(['consultant_id' => $one->id, 'admit_date' => now()->toDateString()]);
        $this->admission(['consultant_id' => $one->id, 'admit_date' => now()->subDays(10)->toDateString()]);
        // ICU rows are excluded from this block (legacy parity) — must NOT bump Dr Day One
        $this->admission(['consultant_id' => $one->id, 'admit_date' => now()->toDateString(), 'current_location' => 'ICU']);
        // Dr Day Two: one discharge yesterday
        $this->admission(['consultant_id' => $two->id, 'admit_date' => now()->subDays(20)->toDateString(),
            'discharge_date' => now()->subDay()->toDateString(), 'transfer_type' => 'discharge from ward', 'outcome' => 'Alive']);
        // Dr Idle: nothing recent — must be absent
        $this->admission(['consultant_id' => $idle->id, 'admit_date' => now()->subDays(15)->toDateString()]);
        // unassigned admission today — no consultant, not counted
        $this->admission(['admit_date' => now()->toDateString()]);
        // legacy parity: the block joins ACTIVE CONSULTANTS only (position=3, active=1) —
        // an inactive consultant and a registrar with activity today must both be absent
        $inactive = $this->user(User::ROLE_CONSULTANT, ['full_name' => 'Dr Gone', 'active' => 0]);
        $registrar = $this->user(User::ROLE_REGISTRAR, ['full_name' => 'Dr Registrar']);
        $this->admission(['consultant_id' => $inactive->id, 'admit_date' => now()->toDateString()]);
        $this->admission(['consultant_id' => $registrar->id, 'admit_date' => now()->toDateString()]);

        $this->actingAs($this->admin())->get('/dashboard')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->count('activity24h', 2)
                ->where('activity24h.0.name', 'Dr Day One')
                ->where('activity24h.0.admissions', 1)
                ->where('activity24h.0.discharges', 0)
                ->where('activity24h.1.name', 'Dr Day Two')
                ->where('activity24h.1.admissions', 0)
                ->where('activity24h.1.discharges', 1));
    }

    public function test_dashboard_ytd_strip_hand_computed(): void
    {
        $yearStart = now()->startOfYear();

        // admissions: 2 non-ICU this year; 1 ICU (excluded); 1 last year (excluded)
        $this->admission(['admit_date' => $yearStart->copy()->addDays(5)->toDateString()]);
        $this->admission(['admit_date' => $yearStart->copy()->addDays(7)->toDateString(),
            'discharge_date' => $yearStart->copy()->addDays(10)->toDateString(),
            'transfer_type' => 'discharge from ward', 'outcome' => 'Alive']);
        $this->admission(['admit_date' => $yearStart->copy()->addDays(6)->toDateString(), 'current_location' => 'ICU']);
        $this->admission(['admit_date' => $yearStart->copy()->subDays(10)->toDateString(),
            'discharge_date' => $yearStart->copy()->subDays(5)->toDateString(),
            'transfer_type' => 'discharge from ward', 'outcome' => 'Alive']);

        // consultations: 2 this year (1 signed off); 1 last year (excluded)
        Consultation::create(['mrn' => '2001', 'patient_name' => 'C One',
            'consultation_date' => $yearStart->copy()->addDays(3)->toDateString()]);
        Consultation::create(['mrn' => '2002', 'patient_name' => 'C Two',
            'consultation_date' => $yearStart->copy()->addDays(2)->toDateString(),
            'signoff_date' => $yearStart->copy()->addDays(4)->toDateString()]);
        Consultation::create(['mrn' => '2003', 'patient_name' => 'C Old',
            'consultation_date' => $yearStart->copy()->subDays(8)->toDateString(),
            'signoff_date' => $yearStart->copy()->subDays(6)->toDateString()]);

        $this->actingAs($this->admin())->get('/dashboard')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('ytd.admissions', 2)
                ->where('ytd.discharges', 1)
                ->where('ytd.consultations', 2)
                ->where('ytd.signoffs', 1));
    }
}
