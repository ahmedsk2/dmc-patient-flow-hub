<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\AuditLog;
use App\Models\Consultation;
use App\Models\Handover;
use App\Models\HandoverRevision;
use App\Models\HandoverSignature;
use App\Models\Patient;
use App\Models\Setting;
use App\Models\Specialty;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Round-9 fix batch L1 (items 1–2, the legacy-import nationality + assigned_at backfill,
 * live in LegacyImportTest):
 *  3.  EnsureMfaEnrolled enforcement matrix — policy 0/1/2 scope, enrolled users pass,
 *      setup/confirm/logout stay reachable while unenrolled
 *  4.  external transfer to 'Intensive Care (ICU)' must NOT stamp assigned_at on the receiving
 *      episode (legacy left newassign untouched — no 24h New badge); column assert in Round6K1Test
 *  5.  when a patient's LAST open episode closes (complete/icu/one-step discharge, delete),
 *      unsigned handover signatures across ALL that patient's admissions are voided — a
 *      specialty-transfer signature lives on the CLOSED episode and must not dangle forever
 *  6.  duplicate-MRN repoint guard: an ACTIVE admission cannot be repointed onto a patient who
 *      already has an ACTIVE admission (the board would show the same patient twice)
 *  7.  assign-to-me is for the UNASSIGNED queue only — it must not silently take over an
 *      already-assigned patient (that path is Assign/Transfer, which carry the handover gate)
 *  9.  MfaController::confirm pin: secret encrypted at rest, recovery codes hashed,
 *      mfa_last_counter set (enrollment code not replayable as the first challenge)
 *  10. ControlController::resetMfa pin: admin clears enrollment; non-admin 403
 *  11. consultation signoff pin: owning consultant succeeds (+audit); non-owner w/o manage 403
 *  12. consultation delete pin: admin deletes (+audit); non-admin 403
 *  13. reverse-discharge / reverse-signoff pin: non-admin (even consultant WITH manage) 403
 *  14. board chips share the boardGroups D1 exemption on view=longterm / view=tb (unit-wide)
 */
class Round9L1Test extends TestCase
{
    use RefreshDatabase;

    private function user(int $role = User::ROLE_CONSULTANT, array $extra = []): User
    {
        return User::create(array_merge([
            'username' => 'l1_' . substr(md5(uniqid('', true)), 0, 10),
            'name' => 'L1 User', 'password' => 'secret12345', 'role' => $role, 'active' => 1,
        ], $extra));
    }

    private function admin(): User
    {
        return $this->user(User::ROLE_ADMIN);
    }

    private function admission(array $overrides = [], ?Patient $patient = null): Admission
    {
        $p = $patient ?? Patient::create(['mrn' => (string) random_int(10000000, 99999999), 'name' => 'L1 Patient']);

        return Admission::create(array_merge([
            'patient_id' => $p->id, 'admit_date' => now()->subDays(3)->toDateString(),
            'current_location' => 'Ward', 'is_longterm' => 0, 'is_new_assignment' => 0,
        ], $overrides));
    }

    /** Seed a handover updated TODAY (satisfies the same-day transfer gate). */
    private function freshHandover(Admission $a, User $author): void
    {
        Handover::create(['admission_id' => $a->id, 'body' => 'Stable. Continue plan.', 'updated_by' => $author->id]);
        HandoverRevision::create(['admission_id' => $a->id, 'body' => 'Stable. Continue plan.', 'author_id' => $author->id]);
    }

    /** Valid 6-digit code for $secret at the current time-step (private Totp::code via reflection). */
    private function currentCode(string $secret, int $offset = 0): string
    {
        $m = new \ReflectionMethod(Totp::class, 'code');
        $m->setAccessible(true);

        return $m->invoke(null, $secret, intdiv(time(), 30) + $offset);
    }

    /** A 6-digit code guaranteed INVALID for $secret right now (differs from the ±1 window). */
    private function wrongCode(string $secret): string
    {
        $live = [$this->currentCode($secret, -1), $this->currentCode($secret), $this->currentCode($secret, 1)];
        for ($c = 0; ; $c++) {
            $candidate = str_pad((string) $c, 6, '0', STR_PAD_LEFT);
            if (! in_array($candidate, $live, true)) {
                return $candidate;
            }
        }
    }

    // ---- 3. EnsureMfaEnrolled enforcement matrix -------------------------------------------------

    public function test_mfa_policy_off_unenrolled_users_pass(): void
    {
        Setting::current()->update(['mfa_enforcement' => 0]);

        $this->actingAs($this->user())->get('/patients')->assertOk();
        $this->actingAs($this->admin())->get('/')->assertOk();
    }

    public function test_mfa_policy_admins_redirects_unenrolled_admin_but_not_consultant(): void
    {
        Setting::current()->update(['mfa_enforcement' => 1]);

        $this->actingAs($this->admin())->get('/')->assertRedirect(route('mfa.setup'));
        $this->actingAs($this->user())->get('/patients')->assertOk();
    }

    public function test_mfa_policy_everyone_redirects_any_unenrolled_user(): void
    {
        Setting::current()->update(['mfa_enforcement' => 2]);

        $this->actingAs($this->user())->get('/patients')->assertRedirect(route('mfa.setup'));
        $this->actingAs($this->admin())->get('/')->assertRedirect(route('mfa.setup'));
    }

    public function test_mfa_policy_enrolled_users_pass_under_full_enforcement(): void
    {
        Setting::current()->update(['mfa_enforcement' => 2]);
        $enrolled = $this->user(User::ROLE_CONSULTANT, [
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
            'mfa_recovery_codes' => [Hash::make('AAAA-BBBB')],
        ]);

        $this->actingAs($enrolled)->get('/patients')->assertOk();
    }

    public function test_mfa_exempt_routes_reachable_while_unenrolled(): void
    {
        Setting::current()->update(['mfa_enforcement' => 2]);
        $u = $this->user();

        // setup page renders (the funnel target itself must not loop)
        $this->actingAs($u)->get('/mfa/setup')->assertOk();

        // confirm reaches the CONTROLLER (a wrong code yields a validation error, not the
        // enrollment redirect) — without the exemption the middleware would bounce the POST
        $secret = Totp::secret();
        $this->actingAs($u)
            ->withSession(['mfa.setup.secret' => $secret, 'mfa.setup.codes' => ['AAAA-BBBB']])
            ->post('/mfa/confirm', ['code' => $this->wrongCode($secret)])
            ->assertSessionHasErrors('code');

        // logout works — an unenrolled user must be able to leave
        $this->actingAs($this->user())->post('/logout')->assertRedirect();
        $this->assertGuest();
    }

    // ---- 5. patient-wide signature voiding when the last open episode closes ---------------------

    /** Specialty-transfer fixture: returns [$closedEpisodeSignature, $newOpenEpisode]. */
    private function specialtyTransferWithSignature(User $from, User $to): array
    {
        Specialty::firstOrCreate(['name' => 'Cardiology'], ['is_subspecialty' => true, 'is_external' => false]);
        $spec = Specialty::where('name', 'Cardiology')->first();
        $a = $this->admission(['consultant_id' => $from->id]);
        $this->freshHandover($a, $from);

        $this->actingAs($from)->post("/admissions/{$a->id}/transfer", [
            'mode' => 'specialty', 'specialty_id' => $spec->id, 'consultant_id' => $to->id,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $sig = HandoverSignature::where('admission_id', $a->id)->whereNull('signed_at')->whereNull('voided_at')->first();
        $this->assertNotNull($sig, 'specialty transfer must leave a pending signature on the CLOSED episode');
        $new = Admission::where('patient_id', $a->patient_id)->whereNull('discharge_date')->first();
        $this->assertNotNull($new, 'specialty transfer must open the receiving episode');

        return [$sig, $new];
    }

    public function test_specialty_transfer_signature_voided_when_new_episode_is_discharged(): void
    {
        [$sig, $new] = $this->specialtyTransferWithSignature($this->user(), $this->user());

        // while the patient is still on the board, the signature stays pending
        $this->assertNull($sig->fresh()->voided_at, 'signature must stay pending while the patient is active');

        // one-step close of the receiving episode — the patient leaves the unit entirely
        $this->actingAs($this->admin())->post("/admissions/{$new->id}/medical-discharge", [
            'complete' => true, 'outcome' => 'Alive',
            'medical_discharge_date' => now()->toDateString(), 'discharge_to' => 'Home',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertNotNull($sig->fresh()->voided_at,
            'closing the patient\'s LAST open episode must void the signature parked on the older closed episode');
    }

    public function test_signature_stays_pending_while_patient_has_another_open_episode(): void
    {
        [$sig, $new] = $this->specialtyTransferWithSignature($this->user(), $this->user());

        // a second OPEN episode for the same patient (e.g. an unrelated parallel admission row)
        $other = $this->admission(['consultant_id' => $this->user()->id], $new->patient);

        $this->actingAs($this->admin())->post("/admissions/{$new->id}/medical-discharge", [
            'complete' => true, 'outcome' => 'Alive',
            'medical_discharge_date' => now()->toDateString(), 'discharge_to' => 'Home',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertNull($sig->fresh()->voided_at,
            'patient still has an open episode — the pending signature must survive');

        // closing the remaining episode then voids it
        $this->actingAs($this->admin())->post("/admissions/{$other->id}/medical-discharge", [
            'complete' => true, 'outcome' => 'Alive',
            'medical_discharge_date' => now()->toDateString(), 'discharge_to' => 'Home',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNotNull($sig->fresh()->voided_at);
    }

    public function test_icu_discharge_of_last_episode_voids_patient_wide(): void
    {
        [$sig, $new] = $this->specialtyTransferWithSignature($this->user(), $this->user());
        $new->update(['current_location' => 'ICU']);

        $this->actingAs($this->admin())->post("/admissions/{$new->id}/icu-discharge", [
            'outcome' => 'Alive', 'discharge_date' => now()->toDateString(), 'discharge_to' => 'Home',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertNotNull($sig->fresh()->voided_at, 'single-step ICU discharge also voids patient-wide');
    }

    public function test_deleting_last_open_episode_voids_patient_wide(): void
    {
        [$sig, $new] = $this->specialtyTransferWithSignature($this->user(), $this->user());

        // Phase 4 — Item 4: admission delete is step-up gated
        $this->actingAs($this->admin())->withSession(['stepup.verified_at' => now()->getTimestamp()])
            ->delete("/admissions/{$new->id}")
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertNotNull($sig->fresh()->voided_at,
            'soft-deleting the patient\'s last open episode must void the older episode\'s signature');
    }

    // ---- 6. duplicate-MRN repoint guard -----------------------------------------------------------

    /** Modify payload that repoints $a onto MRN $mrn (demographics unchanged). */
    private function repointPayload(Admission $a, string $mrn): array
    {
        return [
            'mrn' => $mrn, 'name' => $a->patient->name, 'age' => $a->patient->age,
            'gender' => $a->patient->gender, 'nationality' => $a->patient->nationality,
            'bed' => $a->bed, 'admit_date' => $a->admit_date->toDateString(),
            'admitted_from' => $a->admitted_from, 'current_location' => $a->current_location,
            'diagnoses' => [],
        ];
    }

    public function test_active_admission_cannot_repoint_onto_patient_with_active_admission(): void
    {
        $target = Patient::create(['mrn' => '95000001', 'name' => 'Target Active']);
        $this->admission([], $target);                       // target's ACTIVE episode
        $a = $this->admission();                             // ACTIVE admission being repointed

        $this->actingAs($this->admin())->post("/admissions/{$a->id}/modify", $this->repointPayload($a, '95000001'))
            ->assertSessionHasErrors(['mrn' => 'That patient already has an active admission.']);

        $this->assertNotSame($target->id, (int) $a->fresh()->patient_id, 'the repoint must not happen');
    }

    public function test_repoint_allowed_when_either_side_is_not_active(): void
    {
        // direction 1: a CLOSED admission repointed onto a patient with an active episode — fine
        $target1 = Patient::create(['mrn' => '95000002', 'name' => 'Target Active 2']);
        $this->admission([], $target1);
        $closed = $this->admission(['discharge_date' => now()->subDay()->toDateString(),
            'outcome' => 'Alive', 'transfer_type' => 'discharge from ward']);

        $this->actingAs($this->admin())->post("/admissions/{$closed->id}/modify", $this->repointPayload($closed, '95000002'))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($target1->id, (int) $closed->fresh()->patient_id);

        // direction 2: an ACTIVE admission repointed onto a patient with only CLOSED episodes — fine
        $target2 = Patient::create(['mrn' => '95000003', 'name' => 'Target Closed']);
        $this->admission(['discharge_date' => now()->subDay()->toDateString(),
            'outcome' => 'Alive', 'transfer_type' => 'discharge from ward'], $target2);
        $active = $this->admission();

        $this->actingAs($this->admin())->post("/admissions/{$active->id}/modify", $this->repointPayload($active, '95000003'))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($target2->id, (int) $active->fresh()->patient_id);
    }

    // ---- 7. assign-to-me is unassigned-only --------------------------------------------------------

    public function test_assign_to_me_rejected_when_admission_already_has_a_consultant(): void
    {
        $owner = $this->user();
        $claimer = $this->user();
        $a = $this->admission(['consultant_id' => $owner->id]);

        $this->actingAs($claimer)->post("/admissions/{$a->id}/assign-to-me")
            ->assertRedirect()->assertSessionHas('flash.type', 'error');

        $this->assertSame($owner->id, (int) $a->fresh()->consultant_id,
            'assign-to-me must not take over an assigned patient (handover-gate bypass)');
        $this->assertFalse(AuditLog::where('action', 'admission.assign_to_me')
            ->where('entity_id', (string) $a->id)->exists());
    }

    // ---- 9. MfaController::confirm pin -------------------------------------------------------------

    public function test_mfa_confirm_persists_encrypted_secret_hashed_codes_and_counter(): void
    {
        $u = $this->user();
        $secret = Totp::secret();
        $codes = ['AAAA-1111', 'BBBB-2222'];
        $code = $this->currentCode($secret);

        $this->actingAs($u)
            ->withSession(['mfa.setup.secret' => $secret, 'mfa.setup.codes' => $codes])
            ->post('/mfa/confirm', ['code' => $code])
            ->assertRedirect(route('profile.edit'))->assertSessionHasNoErrors();

        $u->refresh();
        $this->assertSame($secret, $u->mfa_secret, 'model decrypts back to the enrolled secret');
        $raw = DB::table('users')->where('id', $u->id)->value('mfa_secret');
        $this->assertNotSame($secret, $raw, 'secret must be ENCRYPTED at rest, not plaintext');
        $this->assertNotNull($u->mfa_enrolled_at);

        $this->assertCount(2, $u->mfa_recovery_codes);
        foreach ($u->mfa_recovery_codes as $i => $hash) {
            $this->assertNotSame($codes[$i], $hash, 'recovery codes must be stored hashed');
            $this->assertTrue(Hash::check($codes[$i], $hash));
        }

        $this->assertSame(intdiv(time(), 30), (int) $u->mfa_last_counter,
            'mfa_last_counter pins the enrollment time-step');
        $this->assertTrue(AuditLog::where('action', 'mfa.enable')->where('entity_id', (string) $u->id)->exists());

        // the enrollment code is NOT replayable as the first login challenge
        $this->post('/logout');
        $this->withSession(['mfa.pending.id' => $u->id, 'mfa.pending.at' => now()->getTimestamp()])
            ->post('/mfa/challenge', ['code' => $code])
            ->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_mfa_confirm_rejects_wrong_code_and_does_not_enroll(): void
    {
        $u = $this->user();
        $secret = Totp::secret();

        $this->actingAs($u)
            ->withSession(['mfa.setup.secret' => $secret, 'mfa.setup.codes' => ['AAAA-1111']])
            ->post('/mfa/confirm', ['code' => $this->wrongCode($secret)])
            ->assertSessionHasErrors('code');

        $u->refresh();
        $this->assertNull($u->mfa_secret);
        $this->assertNull($u->mfa_enrolled_at);
        $this->assertNull($u->mfa_last_counter);
    }

    // ---- 10. ControlController::resetMfa pin --------------------------------------------------------

    private function enrolledUser(): User
    {
        return $this->user(User::ROLE_CONSULTANT, [
            'mfa_secret' => Totp::secret(),
            'mfa_recovery_codes' => [Hash::make('AAAA-BBBB')],
            'mfa_enrolled_at' => now(),
        ]);
    }

    public function test_admin_reset_mfa_clears_enrollment_with_audit(): void
    {
        $u = $this->enrolledUser();

        $this->actingAs($this->admin())->post("/control/users/{$u->id}/reset-mfa")
            ->assertRedirect()->assertSessionHas('flash.type', 'success');

        $u->refresh();
        $this->assertNull($u->mfa_secret);
        $this->assertNull($u->mfa_recovery_codes);
        $this->assertNull($u->mfa_enrolled_at);
        $this->assertFalse($u->mfaEnabled());
        $this->assertTrue(AuditLog::where('action', 'user.reset_mfa')->where('entity_id', (string) $u->id)->exists());
    }

    public function test_non_admin_cannot_reset_mfa(): void
    {
        $u = $this->enrolledUser();

        $this->actingAs($this->user())->post("/control/users/{$u->id}/reset-mfa")->assertForbidden();

        $this->assertTrue($u->fresh()->mfaEnabled(), 'enrollment must be untouched');
    }

    // ---- 11. consultation signoff pin ---------------------------------------------------------------

    private function consultation(User $consultant): Consultation
    {
        return Consultation::create([
            'mrn' => (string) random_int(10000000, 99999999), 'patient_name' => 'L1 Consult Pt',
            'consultation_date' => now()->subDay()->toDateString(), 'consultant_id' => $consultant->id,
        ]);
    }

    public function test_owning_consultant_signs_off_with_audit(): void
    {
        $owner = $this->user();
        $c = $this->consultation($owner);

        $this->actingAs($owner)->post("/consultations/{$c->id}/signoff")
            ->assertRedirect()->assertSessionHas('flash.type', 'success');

        $this->assertSame(now()->toDateString(), $c->fresh()->signoff_date?->toDateString());
        $this->assertTrue(AuditLog::where('action', 'consultation.signoff')->where('entity_id', (string) $c->id)->exists());
    }

    public function test_non_owner_consultant_without_manage_cannot_sign_off(): void
    {
        $c = $this->consultation($this->user());

        $this->actingAs($this->user())->post("/consultations/{$c->id}/signoff")->assertForbidden();

        $this->assertNull($c->fresh()->signoff_date);
    }

    // ---- 12. consultation delete pin ----------------------------------------------------------------

    public function test_admin_deletes_consultation_with_audit(): void
    {
        $c = $this->consultation($this->user());

        $this->actingAs($this->admin())->delete("/consultations/{$c->id}")
            ->assertRedirect()->assertSessionHas('flash.type', 'success');

        $this->assertNull(Consultation::find($c->id));
        $this->assertTrue(AuditLog::where('action', 'consultation.delete')->where('entity_id', (string) $c->id)->exists());
    }

    public function test_non_admin_cannot_delete_consultation(): void
    {
        $owner = $this->user(User::ROLE_CONSULTANT, ['can_manage' => 1]);   // even the owner with Manage
        $c = $this->consultation($owner);

        $this->actingAs($owner)->delete("/consultations/{$c->id}")->assertForbidden();

        $this->assertNotNull(Consultation::find($c->id));
    }

    // ---- 13. reverse-discharge / reverse-signoff: admin only ------------------------------------------

    public function test_non_admin_with_manage_cannot_reverse_discharge(): void
    {
        $manager = $this->user(User::ROLE_CONSULTANT, ['can_manage' => 1]);
        $a = $this->admission(['discharge_date' => now()->toDateString(),
            'medical_discharge_date' => now()->toDateString(), 'outcome' => 'Alive',
            'transfer_type' => 'discharge from ward']);

        // pass the step-up gate (Item 4) so the controller's admin-only check is what denies (403)
        $this->actingAs($manager)->withSession(['stepup.verified_at' => now()->getTimestamp()])
            ->post("/admissions/{$a->id}/reverse-discharge")->assertForbidden();

        $this->assertNotNull($a->fresh()->discharge_date, 'the discharge must stand');
    }

    public function test_non_admin_with_manage_cannot_reverse_signoff(): void
    {
        $manager = $this->user(User::ROLE_CONSULTANT, ['can_manage' => 1]);
        $c = $this->consultation($manager);
        $c->update(['signoff_date' => now()->toDateString()]);

        $this->actingAs($manager)->post("/consultations/{$c->id}/reverse-signoff")->assertForbidden();

        $this->assertNotNull($c->fresh()->signoff_date, 'the sign-off must stand');
    }

    // ---- 14. chips share the longterm/tb D1 exemption ---------------------------------------------

    public function test_consultant_chips_are_unit_wide_on_longterm_and_tb_views(): void
    {
        \App\Models\Icd10::create(['code' => 'A15.0', 'name' => 'Tuberculosis of lung']);
        \App\Models\TbDiagnosis::create(['icd10_code' => 'A15.0']);
        $me = $this->user(User::ROLE_CONSULTANT, ['full_name' => 'Dr Me']);
        $other = $this->user(User::ROLE_CONSULTANT, ['full_name' => 'Dr Other']);
        $this->admission(['consultant_id' => $me->id, 'is_longterm' => 1]);
        $this->admission(['consultant_id' => $other->id, 'is_longterm' => 1]);
        $tb = $this->admission(['consultant_id' => $other->id]);
        $tb->diagnoses()->create(['seq' => 1, 'icd10_code' => 'A15.0']);

        // N1-6: stats.longterm/stats.tb were removed (Patients/Index.vue never read them); the
        // unit-wide-vs-own-only D1 exemption is still asserted through stats.total, the chip the
        // board actually renders.
        // longterm view: stats count the UNIT, matching the unit-wide board below them
        $this->actingAs($me)->get('/patients?view=longterm')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('stats.total', 3));

        // tb view: same exemption
        $this->actingAs($me)->get('/patients?view=tb')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('stats.total', 3));

        // the DEFAULT board keeps the D1 own-only scoping
        $this->actingAs($me)->get('/patients')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('stats.total', 1));
    }
}
