<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Consultation;
use App\Models\ConsultationReason;
use App\Models\Setting;
use App\Models\Specialty;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 2026-09-24 role/UX review — g7-control-admin fixes (ROLE-UX-REVIEW-2026-09-24.md §2):
 *   #2  duplicate specialty/consultation-reason names are now rejected (case/space-insensitive),
 *       and both gain rename + delete-if-unused routes.
 *   #3  Control → Settings refuses short_los >= long_los.
 *   #18 a never-yet-activated self-registration is now distinguishable from an admin-deactivated
 *       account (Control/Index.vue's "Awaiting activation" badge reads this prop).
 */
class UxReviewG7ControlAdminTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'username' => 'g7a_'.substr(md5(uniqid('', true)), 0, 8), 'name' => 'G7 Admin',
            'password' => 'secret12345', 'role' => User::ROLE_ADMIN, 'active' => 1,
            'email' => 'g7admin_'.uniqid('', true).'@dmc-im.com', 'email_verified_at' => now(),
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(), 'pass_exp_date' => now()->toDateString(),
        ]);
    }

    private function consultant(): User
    {
        return User::create([
            'username' => 'g7c_'.substr(md5(uniqid('', true)), 0, 8), 'name' => 'G7 Consultant',
            'password' => 'secret12345', 'role' => User::ROLE_CONSULTANT, 'active' => 1,
            'email' => 'g7cons_'.uniqid('', true).'@dmc-im.com', 'email_verified_at' => now(),
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(), 'pass_exp_date' => now()->toDateString(),
        ]);
    }

    /** A full, otherwise-unchanged settings payload (mirrors ControlSettingsLogRecordOpensTest). */
    private function settingsPayload(array $overrides = []): array
    {
        $s = Setting::current();

        return array_merge($s->only([
            'min_hospitalist', 'max_hospitalist', 'min_subs', 'max_subs', 'short_los', 'long_los',
            'ward_beds', 'icu_beds', 'readmission_window_days', 'mfa_enforcement',
            'mfa_trusted_device_hours', 'idle_timeout_minutes', 'abs_timeout_minutes',
            'failed_login_notify_threshold', 'dq_los_multiplier', 'alert_overcensus_pct',
            'alert_boarding_max', 'alert_readmit_rate_pct', 'alert_deaths_delta_pct',
        ]), $overrides);
    }

    /* ---------------------------------------------------------------- #2: specialties ---------------------------------------------------------------- */

    public function test_add_specialty_rejects_a_case_and_space_variant_duplicate(): void
    {
        $admin = $this->admin();
        Specialty::create(['name' => 'Nephrology']);

        $this->actingAs($admin)->post('/control/specialties', ['name' => ' nephrology '])
            ->assertSessionHasErrors('name');
        $this->assertSame(1, Specialty::where('name', 'Nephrology')->count());
    }

    public function test_rename_specialty_is_audited_with_before_and_after(): void
    {
        $admin = $this->admin();
        $s = Specialty::create(['name' => 'Cardiologi']);

        $this->actingAs($admin)->put("/control/specialties/{$s->id}", ['name' => 'Cardiology'])
            ->assertRedirect();

        $this->assertSame('Cardiology', $s->fresh()->name);
        $row = AuditLog::where('action', 'specialty.rename')->where('entity_id', (string) $s->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('Cardiologi', $row->details['from']);
        $this->assertSame('Cardiology', $row->details['to']);
    }

    public function test_rename_specialty_to_an_existing_name_is_refused(): void
    {
        $admin = $this->admin();
        Specialty::create(['name' => 'Nephrology']);
        $s = Specialty::create(['name' => 'Cardiology']);

        $this->actingAs($admin)->put("/control/specialties/{$s->id}", ['name' => ' NEPHROLOGY '])
            ->assertSessionHasErrors('name');
        $this->assertSame('Cardiology', $s->fresh()->name);
    }

    public function test_delete_unused_specialty_succeeds_and_is_audited(): void
    {
        $admin = $this->admin();
        $s = Specialty::create(['name' => 'Unused Specialty']);

        $this->actingAs($admin)->delete("/control/specialties/{$s->id}")->assertRedirect();

        $this->assertDatabaseMissing('specialties', ['id' => $s->id]);
        $this->assertDatabaseHas('audit_log', ['action' => 'specialty.delete', 'entity_id' => (string) $s->id]);
    }

    public function test_delete_specialty_assigned_to_a_user_is_refused(): void
    {
        $admin = $this->admin();
        $s = Specialty::create(['name' => 'In-Use Specialty']);
        User::create(['username' => 'g7u_'.substr(md5(uniqid('', true)), 0, 8), 'name' => 'Dr Y',
            'password' => 'secret12345', 'role' => User::ROLE_CONSULTANT, 'active' => 1, 'specialty_id' => $s->id,
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now()]);

        $this->actingAs($admin)->delete("/control/specialties/{$s->id}")
            ->assertRedirect()->assertSessionHas('flash.type', 'error');
        $this->assertDatabaseHas('specialties', ['id' => $s->id]);
    }

    public function test_delete_specialty_referenced_by_a_consultation_is_refused(): void
    {
        $admin = $this->admin();
        $s = Specialty::create(['name' => 'Ledger-Used Specialty']);
        Consultation::create(['owning_specialty_id' => $s->id]);

        $this->actingAs($admin)->delete("/control/specialties/{$s->id}")
            ->assertRedirect()->assertSessionHas('flash.type', 'error');
        $this->assertDatabaseHas('specialties', ['id' => $s->id]);
    }

    public function test_non_admin_cannot_rename_or_delete_a_specialty(): void
    {
        $consultant = $this->consultant();
        $s = Specialty::create(['name' => 'Guarded Specialty']);

        $this->actingAs($consultant)->put("/control/specialties/{$s->id}", ['name' => 'x'])->assertForbidden();
        $this->actingAs($consultant)->delete("/control/specialties/{$s->id}")->assertForbidden();
        $this->assertDatabaseHas('specialties', ['id' => $s->id, 'name' => 'Guarded Specialty']);
    }

    /**
     * Review fix (major): ShuffleService, DashboardController's "Census by service" split and
     * PatientsController's on-service ranking all hardcode literal specialty_id 1 as "the
     * Hospitalist pool" — not by FK, not by is_subspecialty. Deleting it once unreferenced would
     * silently and permanently break all three (MySQL never reuses a freed auto-increment id).
     * `$ignoreId` truthy-vs-null aside, id 1 must never be deletable, referenced or not.
     */
    public function test_delete_specialty_id_1_is_refused_even_when_completely_unused(): void
    {
        $admin = $this->admin();
        DB::table('specialties')->insert(['id' => 1, 'name' => 'Hospitalist Guard '.uniqid(),
            'is_subspecialty' => 0, 'is_external' => 0, 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($admin)->delete('/control/specialties/1')
            ->assertRedirect()->assertSessionHas('flash.type', 'error');
        $this->assertDatabaseHas('specialties', ['id' => 1]);
    }

    /* ---------------------------------------------------------------- #2: consultation reasons ---------------------------------------------------------------- */

    public function test_add_reason_rejects_a_case_and_space_variant_duplicate(): void
    {
        $admin = $this->admin();
        ConsultationReason::create(['name' => 'Sepsis review']);

        $this->actingAs($admin)->post('/control/reasons', ['name' => ' SEPSIS REVIEW '])
            ->assertSessionHasErrors('name');
    }

    public function test_rename_reason_to_a_duplicate_is_refused_then_a_real_rename_succeeds(): void
    {
        $admin = $this->admin();
        ConsultationReason::create(['name' => 'Taken']);
        $r = ConsultationReason::create(['name' => 'Typoo']);

        $this->actingAs($admin)->put("/control/reasons/{$r->id}", ['name' => ' taken '])
            ->assertSessionHasErrors('name');
        $this->assertSame('Typoo', $r->fresh()->name);

        $this->actingAs($admin)->put("/control/reasons/{$r->id}", ['name' => 'Typo'])->assertRedirect();
        $this->assertSame('Typo', $r->fresh()->name);
    }

    public function test_delete_unused_reason_succeeds(): void
    {
        $admin = $this->admin();
        $r = ConsultationReason::create(['name' => 'Unused Reason']);

        $this->actingAs($admin)->delete("/control/reasons/{$r->id}")->assertRedirect();
        $this->assertDatabaseMissing('consultation_reasons', ['id' => $r->id]);
    }

    public function test_delete_reason_referenced_in_a_consultations_indication_list_is_refused(): void
    {
        $admin = $this->admin();
        $r = ConsultationReason::create(['name' => 'In-Use Reason']);
        Consultation::create(['indication' => [$r->id]]);

        $this->actingAs($admin)->delete("/control/reasons/{$r->id}")
            ->assertRedirect()->assertSessionHas('flash.type', 'error');
        $this->assertDatabaseHas('consultation_reasons', ['id' => $r->id]);
    }

    public function test_non_admin_cannot_rename_or_delete_a_reason(): void
    {
        $consultant = $this->consultant();
        $r = ConsultationReason::create(['name' => 'Guarded Reason']);

        $this->actingAs($consultant)->put("/control/reasons/{$r->id}", ['name' => 'x'])->assertForbidden();
        $this->actingAs($consultant)->delete("/control/reasons/{$r->id}")->assertForbidden();
        $this->assertDatabaseHas('consultation_reasons', ['id' => $r->id, 'name' => 'Guarded Reason']);
    }

    /**
     * Review fix (major): consultation_reasons id 0 is the real "Other" sentinel
     * (DATABASE-AND-BEHAVIOR.md: "id 0 = Other -> free text required"), hardcoded by id in
     * ConsultationRequest::rules() and by literal placeholder text in Consultations/Index.vue.
     * Deleting it once unreferenced would silently remove "Other" from every future consult form.
     */
    public function test_delete_reason_id_0_other_sentinel_is_refused_even_when_unused(): void
    {
        $admin = $this->admin();
        // MySQL treats an explicit 0 on an AUTO_INCREMENT column the same as NULL (assigns the next
        // id) unless NO_AUTO_VALUE_ON_ZERO is set — the same technique LegacyImport uses to preserve
        // the real id=0 "Other" row; without it this insert would silently NOT create id 0.
        DB::statement("SET SESSION sql_mode='NO_AUTO_VALUE_ON_ZERO'");
        DB::table('consultation_reasons')->insert(['id' => 0, 'name' => 'Other']);

        $this->actingAs($admin)->delete('/control/reasons/0')
            ->assertRedirect()->assertSessionHas('flash.type', 'error');
        $this->assertDatabaseHas('consultation_reasons', ['id' => 0]);
    }

    /**
     * Review fix (major + minor): rename of id 0 is refused outright (its label must never drift
     * from the hardcoded "Other" placeholder text) — this also sidesteps the `nameTaken()` truthy-vs-
     * null bug the reviewer found (int 0 was falsy, so `->when($ignoreId, ...)` never excluded row 0
     * from its own duplicate check, falsely rejecting a same-name rename). `nameTaken()` itself is
     * fixed to use `!is_null($ignoreId)` regardless, since it is the general-purpose guard.
     */
    public function test_rename_reason_id_0_other_sentinel_is_refused_even_to_its_own_name(): void
    {
        $admin = $this->admin();
        DB::statement("SET SESSION sql_mode='NO_AUTO_VALUE_ON_ZERO'");
        DB::table('consultation_reasons')->insert(['id' => 0, 'name' => 'Other']);

        $this->actingAs($admin)->put('/control/reasons/0', ['name' => 'Other'])
            ->assertRedirect()->assertSessionHas('flash.type', 'error');
        $this->assertDatabaseHas('consultation_reasons', ['id' => 0, 'name' => 'Other']);
    }

    /* ---------------------------------------------------------------- #3: short_los must be < long_los ---------------------------------------------------------------- */

    public function test_settings_rejects_an_inverted_or_equal_los_band(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->put('/control/settings', $this->settingsPayload(['short_los' => 20, 'long_los' => 11]))
            ->assertSessionHasErrors(['short_los', 'long_los']);

        $this->actingAs($admin)->put('/control/settings', $this->settingsPayload(['short_los' => 11, 'long_los' => 11]))
            ->assertSessionHasErrors(['short_los', 'long_los']);

        // neither rejected payload actually persisted
        $this->assertNotSame(20, (int) Setting::current()->short_los);
    }

    public function test_settings_accepts_a_valid_short_long_los_pair(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->put('/control/settings', $this->settingsPayload(['short_los' => 5, 'long_los' => 11]))
            ->assertSessionHasNoErrors();
        $this->assertSame(5, (int) Setting::current()->short_los);
        $this->assertSame(11, (int) Setting::current()->long_los);
    }

    /* ---------------------------------------------------------------- #18: pending self-registration badge ---------------------------------------------------------------- */

    public function test_a_never_activated_self_registration_is_flagged_pending(): void
    {
        $admin = $this->admin();
        $pending = User::create([
            'username' => 'g7pending', 'name' => 'Pending Nurse', 'full_name' => 'Pending Nurse',
            'password' => 'secret12345', 'role' => User::ROLE_RESIDENT, 'active' => 0,
            'email' => 'pending_'.uniqid('', true).'@dmc-im.com', 'email_verified_at' => now(),
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
        ]);
        AuditLog::create([
            'actor_id' => $pending->id, 'actor_name' => $pending->name, 'action' => 'user.self_register',
            'entity_type' => 'user', 'entity_id' => (string) $pending->id,
        ]);

        $this->actingAs($admin)->get('/control')->assertInertia(fn ($p) => $p
            ->where('users', fn ($users) => collect($users)->firstWhere('id', $pending->id)['pending_registration'] === true
                && collect($users)->firstWhere('id', $pending->id)['registered_at'] !== null));
    }

    public function test_a_user_activated_then_later_deactivated_again_is_not_flagged_pending(): void
    {
        $admin = $this->admin();
        $user = User::create([
            'username' => 'g7deactivated', 'name' => 'Was Active', 'full_name' => 'Was Active',
            'password' => 'secret12345', 'role' => User::ROLE_RESIDENT, 'active' => 0,
            'email' => 'wasactive_'.uniqid('', true).'@dmc-im.com', 'email_verified_at' => now(),
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
        ]);
        AuditLog::create([
            'actor_id' => $user->id, 'actor_name' => $user->name, 'action' => 'user.self_register',
            'entity_type' => 'user', 'entity_id' => (string) $user->id,
        ]);
        // an admin activated it (active: false -> true) — this is what a real Control -> Users
        // "activate" edit writes via ControlController::updateUser's AuditDiff — and later switched
        // it off again; only the ACTIVATE diff needs to exist for the derivation to stop treating it
        // as still-pending.
        AuditLog::create([
            'actor_id' => $admin->id, 'actor_name' => $admin->name, 'action' => 'user.update',
            'entity_type' => 'user', 'entity_id' => (string) $user->id,
            'details' => ['active' => ['from' => false, 'to' => true]],
        ]);

        $this->actingAs($admin)->get('/control')->assertInertia(fn ($p) => $p
            ->where('users', fn ($users) => collect($users)->firstWhere('id', $user->id)['pending_registration'] === false));
    }

    public function test_an_ordinary_admin_deactivated_account_with_no_self_registration_row_is_not_flagged_pending(): void
    {
        $admin = $this->admin();
        // legacy-imported style account: never self-registered, simply inactive
        $legacy = User::create([
            'username' => 'g7legacy', 'name' => 'Legacy Import', 'full_name' => 'Legacy Import',
            'password' => 'secret12345', 'role' => User::ROLE_RESIDENT, 'active' => 0,
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
        ]);

        $this->actingAs($admin)->get('/control')->assertInertia(fn ($p) => $p
            ->where('users', fn ($users) => collect($users)->firstWhere('id', $legacy->id)['pending_registration'] === false));
    }
}
