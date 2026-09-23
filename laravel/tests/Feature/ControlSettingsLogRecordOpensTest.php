<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\AuditLog;
use App\Models\Patient;
use App\Models\Setting;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 2026-09-23 walkthrough (A): settings.log_record_opens (the break-glass "log every record/handover
 * open" flag) was fully enforced server-side — AdmissionsController::edit(), HandoverController::show()
 * — and ControlController::updateSettings() already validated + persisted it ('sometimes','boolean'),
 * but Control -> Settings had no checkbox for it, so it could never be switched on in production. This
 * proves the backend round-trip end to end through the real PUT /control/settings endpoint (the new
 * checkbox's own wiring is covered by ControlIndex.tabs.test.js), independent of the new UI control.
 */
class ControlSettingsLogRecordOpensTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'username' => 'lro_'.substr(md5(uniqid('', true)), 0, 8),
            'name' => 'LRO Admin', 'password' => 'secret12345', 'role' => User::ROLE_ADMIN, 'active' => 1,
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
            'email_verified_at' => now(), 'pass_exp_date' => now()->toDateString(),
        ]);
    }

    /** A full, otherwise-unchanged settings payload with log_record_opens overridden. */
    private function settingsPayload(bool $logRecordOpens): array
    {
        $s = Setting::current();

        return array_merge($s->only([
            'min_hospitalist', 'max_hospitalist', 'min_subs', 'max_subs', 'short_los', 'long_los',
            'ward_beds', 'icu_beds', 'readmission_window_days', 'mfa_enforcement',
            'mfa_trusted_device_hours', 'idle_timeout_minutes', 'abs_timeout_minutes',
            'failed_login_notify_threshold', 'dq_los_multiplier', 'alert_overcensus_pct',
            'alert_boarding_max', 'alert_readmit_rate_pct', 'alert_deaths_delta_pct',
        ]), ['log_record_opens' => $logRecordOpens]);
    }

    private function admission(): Admission
    {
        $p = Patient::create(['mrn' => '80000099', 'name' => 'LRO Patient']);

        return Admission::create(['patient_id' => $p->id, 'admit_date' => '2024-01-10',
            'current_location' => 'Ward', 'is_longterm' => 0, 'is_new_assignment' => 0]);
    }

    public function test_put_control_settings_with_log_record_opens_1_persists_it(): void
    {
        $admin = $this->admin();
        $this->assertFalse((bool) Setting::current()->log_record_opens);

        $this->actingAs($admin)->put('/control/settings', $this->settingsPayload(true))
            ->assertSessionHasNoErrors();

        $this->assertTrue((bool) Setting::current()->log_record_opens);
    }

    public function test_turning_it_on_via_the_settings_endpoint_makes_opening_an_admission_write_the_break_glass_row(): void
    {
        $admin = $this->admin();
        $a = $this->admission();

        // still off — opening the record writes nothing
        $this->actingAs($admin)->getJson("/admissions/{$a->id}/edit")->assertOk();
        $this->assertSame(0, AuditLog::where('action', 'registry.open')->count());

        $this->actingAs($admin)->put('/control/settings', $this->settingsPayload(true))
            ->assertSessionHasNoErrors();

        $this->actingAs($admin)->getJson("/admissions/{$a->id}/edit")->assertOk();
        $row = AuditLog::where('action', 'registry.open')->first();
        $this->assertNotNull($row);
        $this->assertSame((string) $a->id, $row->entity_id);
    }

    public function test_put_control_settings_with_log_record_opens_0_turns_it_off(): void
    {
        $admin = $this->admin();
        $a = $this->admission();
        Setting::current()->update(['log_record_opens' => true]);

        $this->actingAs($admin)->put('/control/settings', $this->settingsPayload(false))
            ->assertSessionHasNoErrors();

        $this->assertFalse((bool) Setting::current()->log_record_opens);

        // back off — opening the record writes no NEW row
        $this->actingAs($admin)->getJson("/admissions/{$a->id}/edit")->assertOk();
        $this->assertSame(0, AuditLog::where('action', 'registry.open')->count());
    }
}
