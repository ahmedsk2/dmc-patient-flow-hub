<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Task 10b — the consultation-ledger cutover gate (`settings.consultations_source_of_truth`)
 * must be flippable from Control, admin-only, and recorded in the append-only setting_changes
 * history like every other Control setting.
 */
class ConsultationImportSafetyTest extends TestCase
{
    use RefreshDatabase;

    private function user(int $role = User::ROLE_CONSULTANT, array $extra = []): User
    {
        return User::create(array_merge([
            'username' => 'cist_'.substr(md5(uniqid('', true)), 0, 10),
            'name' => 'CIST User', 'password' => 'secret12345', 'role' => $role, 'active' => 1,
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
            'email_verified_at' => now(), 'pass_exp_date' => now()->toDateString(),
        ], $extra));
    }

    private function admin(): User
    {
        return $this->user(User::ROLE_ADMIN);
    }

    private function consultant(): User
    {
        return $this->user(User::ROLE_CONSULTANT);
    }

    /**
     * A complete, currently-valid /control/settings payload. Every key in updateSettings()'s
     * validator is `required` except the booleans, so a partial payload 422s for unrelated
     * reasons — mirrors the payload idiom already used in ClinicalFlowTest/TrustedDeviceTest.
     */
    private function settingsPayload(): array
    {
        $s = Setting::current();

        return [
            'min_hospitalist' => $s->min_hospitalist, 'max_hospitalist' => $s->max_hospitalist,
            'min_subs' => $s->min_subs, 'max_subs' => $s->max_subs,
            'short_los' => $s->short_los, 'long_los' => $s->long_los,
            'ward_beds' => $s->ward_beds ?? 50, 'icu_beds' => $s->icu_beds ?? 10,
            'readmission_window_days' => $s->readmission_window_days ?? 3,
            'mfa_enforcement' => $s->mfa_enforcement ?? 0,
            'mfa_trusted_device_hours' => $s->mfa_trusted_device_hours ?? 24,
            'idle_timeout_minutes' => $s->idle_timeout_minutes ?? 30,
            'abs_timeout_minutes' => $s->abs_timeout_minutes ?? 0,
            'failed_login_notify_threshold' => $s->failed_login_notify_threshold ?? 5,
            'dq_los_multiplier' => $s->dq_los_multiplier ?? 2,
            'alert_overcensus_pct' => $s->alert_overcensus_pct ?? 100,
            'alert_boarding_max' => $s->alert_boarding_max ?? 5,
            'alert_readmit_rate_pct' => $s->alert_readmit_rate_pct ?? 10,
            'alert_deaths_delta_pct' => $s->alert_deaths_delta_pct ?? 50,
        ];
    }

    public function test_admin_can_toggle_consultations_source_of_truth_and_it_is_recorded(): void
    {
        $admin = $this->admin();
        $payload = array_merge($this->settingsPayload(), ['consultations_source_of_truth' => true]);

        $this->actingAs($admin)->put('/control/settings', $payload)->assertRedirect();

        $this->assertTrue((bool) DB::table('settings')->value('consultations_source_of_truth'));
        $this->assertDatabaseHas('setting_changes', ['field' => 'consultations_source_of_truth']);
    }

    public function test_non_admin_cannot_toggle_consultations_source_of_truth(): void
    {
        $consultant = $this->consultant();          // any non-admin clinical user

        $payload = array_merge($this->settingsPayload(), ['consultations_source_of_truth' => true]);

        $this->actingAs($consultant)->put('/control/settings', $payload)->assertForbidden();
        $this->assertFalse((bool) DB::table('settings')->value('consultations_source_of_truth'));
    }

    /**
     * Setting::current() ships the raw model as Control's `settings` Inertia prop. Without the
     * boolean cast on Setting, this flag serialises as the integer 1, and Vue's `v-model` checkbox
     * (looseEqual(1, true) === false) renders UNCHECKED even while the ledger-protection gate is
     * actually ON — the UI would lie about the state of the switch that protects every consultation.
     * Pin the prop as a strict boolean so that regression can never come back silently.
     */
    public function test_control_index_exposes_consultations_source_of_truth_as_a_strict_boolean(): void
    {
        Setting::current()->update(['consultations_source_of_truth' => true]);

        $this->actingAs($this->admin())->get('/control')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('settings.consultations_source_of_truth', true));
    }

    /**
     * ControlController::updateSettings() writes `setting_changes.new_value` — the append-only,
     * queryable, human-facing history panel (Control/Index.vue). Turning this flag OFF is the most
     * security-relevant change the form can record ("someone disabled ledger protection"): the
     * recorded value must be legible ('0'), not an empty string ((string) false === '').
     */
    public function test_admin_turning_consultations_source_of_truth_off_records_a_legible_value(): void
    {
        Setting::current()->update(['consultations_source_of_truth' => true]);
        $admin = $this->admin();
        $payload = array_merge($this->settingsPayload(), ['consultations_source_of_truth' => false]);

        $this->actingAs($admin)->put('/control/settings', $payload)->assertRedirect();

        $this->assertFalse((bool) DB::table('settings')->value('consultations_source_of_truth'));
        $this->assertDatabaseHas('setting_changes', [
            'field' => 'consultations_source_of_truth',
            'old_value' => '1',
            'new_value' => '0',
        ]);
    }
}
