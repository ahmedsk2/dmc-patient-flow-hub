<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\Patient;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Regression coverage for the clinical flows and analytics that previously had only manual
 * verification: the two-phase discharge state machine, registry filtering, statistics interval
 * bucketing, and the settings change history.
 */
class ClinicalFlowTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'username' => 'cf_' . substr(md5(uniqid('', true)), 0, 8),
            'name' => 'CF Admin', 'password' => 'secret12345', 'role' => User::ROLE_ADMIN, 'active' => 1,
        ]);
    }

    private function admission(array $overrides = []): Admission
    {
        $p = Patient::create(['mrn' => (string) random_int(10000000, 99999999), 'name' => 'Flow Patient']);

        return Admission::create(array_merge([
            'patient_id' => $p->id, 'admit_date' => now()->subDays(5)->toDateString(),
            'current_location' => 'Ward', 'is_longterm' => 0, 'is_new_assignment' => 0,
        ], $overrides));
    }

    public function test_two_phase_discharge_state_machine(): void
    {
        $admin = $this->admin();
        $a = $this->admission();

        // phase 1 - medical discharge: clinically done, still occupying the bed (stays active)
        $this->actingAs($admin)->post("/admissions/{$a->id}/medical-discharge", [
            'outcome' => 'Alive', 'medical_discharge_date' => now()->toDateString(),
            'discharge_to' => 'Home', 'delay_reason' => 'Physical',   // R1: controlled vocab (Physical|System)
        ])->assertRedirect();
        $a->refresh();
        $this->assertNotNull($a->medical_discharge_date);
        $this->assertNull($a->discharge_date, 'medical discharge must NOT close the episode');

        // phase 2 - complete discharge: closes the file (destination now required at the close, N1-4)
        $this->actingAs($admin)->post("/admissions/{$a->id}/complete-discharge", [
            'discharge_date' => now()->toDateString(), 'discharge_to' => 'Home',
        ])->assertRedirect();
        $a->refresh();
        $this->assertNotNull($a->discharge_date);
        $this->assertSame('discharge from ward', $a->transfer_type);

        // a second complete-discharge is rejected (already discharged)
        $this->actingAs($admin)->post("/admissions/{$a->id}/complete-discharge", [
            'discharge_date' => now()->toDateString(), 'discharge_to' => 'Home',
        ]);
        $this->assertSame(1, Admission::whereNotNull('discharge_date')->count());

        // reverse (admin) returns the patient to active — clears the destination too (legacy nulled DISTO).
        // Phase 4 — Item 4: reverse-discharge is step-up gated.
        $this->actingAs($admin)->withSession(['stepup.verified_at' => now()->getTimestamp()])
            ->post("/admissions/{$a->id}/reverse-discharge")->assertRedirect();
        $a->refresh();
        $this->assertNull($a->discharge_date);
        $this->assertNull($a->medical_discharge_date);
        $this->assertNull($a->discharge_to, 'reverse-discharge must clear discharge_to (legacy DISTO)');
    }

    public function test_icu_discharge_is_single_step(): void
    {
        $admin = $this->admin();
        $a = $this->admission(['current_location' => 'ICU']);

        $this->actingAs($admin)->post("/admissions/{$a->id}/icu-discharge", [
            'outcome' => 'Alive', 'discharge_date' => now()->toDateString(), 'discharge_to' => 'Home',   // destination required (N1-4)
        ])->assertRedirect();
        $a->refresh();
        $this->assertNotNull($a->discharge_date);
        $this->assertSame('discharge from ICU', $a->transfer_type);
    }

    public function test_registry_filters_admissions_correctly(): void
    {
        $admin = $this->admin();
        $this->admission();                                                                       // active ward
        $d = $this->admission(['discharge_date' => now()->toDateString(), 'outcome' => 'Dead']);  // discharged dead
        $d->patient->update(['gender' => 'Female']);

        $this->actingAs($admin)->get('/registry?mode=admissions')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('results.total', 2));
        $this->actingAs($admin)->get('/registry?mode=admissions&discharged=1')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('results.total', 1));
        $this->actingAs($admin)->get('/registry?mode=admissions&outcome=Dead&gender=Female')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('results.total', 1));
        $this->actingAs($admin)->get('/registry?mode=admissions&outcome=Dead&gender=Male')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('results.total', 0));
    }

    public function test_statistics_interval_bucketing(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/statistics?from=2024-01-01&to=2024-12-31&interval=quarter')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('interval', 'quarter')->has('kpiGrid', 4)
                ->where('kpiGrid.0.label', 'Q1 2024')->where('kpiGrid.3.label', 'Q4 2024'));

        $this->actingAs($admin)->get('/statistics?from=2024-06-01&to=2024-06-10&interval=day')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('interval', 'day')->has('kpiGrid', 10));

        $this->actingAs($admin)->get('/statistics?from=2024-01-01&to=2024-03-31')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('interval', 'month')->has('kpiGrid', 3));
    }

    public function test_readmission_window_is_tunable(): void
    {
        $admin = $this->admin();
        // same patient: real discharge 4 days before the new admission
        $p = Patient::create(['mrn' => '77777777', 'name' => 'Window Patient']);
        Admission::create([
            'patient_id' => $p->id, 'admit_date' => now()->subDays(10)->toDateString(),
            'discharge_date' => now()->subDays(4)->toDateString(), 'transfer_type' => 'discharge from ward',
            'current_location' => 'Ward', 'is_longterm' => 0, 'is_new_assignment' => 0,
        ]);
        Admission::create([
            'patient_id' => $p->id, 'admit_date' => now()->toDateString(),
            'current_location' => 'Ward', 'is_longterm' => 0, 'is_new_assignment' => 0,
        ]);

        // default window (3 days): a 4-day gap is NOT a readmission
        $this->actingAs($admin)->get('/registry?mode=admissions&readmit72=1')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('results.total', 0));

        // widen the window to 5 days: now it IS
        Setting::current()->update(['readmission_window_days' => 5]);
        $this->actingAs($admin)->get('/registry?mode=admissions&readmit72=1')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('results.total', 1));
    }

    public function test_settings_change_is_recorded_in_history(): void
    {
        $admin = $this->admin();
        $s = Setting::current();

        $this->actingAs($admin)->put('/control/settings', [
            'min_hospitalist' => $s->min_hospitalist, 'max_hospitalist' => $s->max_hospitalist,
            'min_subs' => $s->min_subs, 'max_subs' => $s->max_subs,
            'short_los' => $s->short_los, 'long_los' => $s->long_los,
            'ward_beds' => 64, 'icu_beds' => $s->icu_beds,
            'readmission_window_days' => $s->readmission_window_days ?? 3,
            'mfa_enforcement' => $s->mfa_enforcement ?? 0,
            // Phase 1 alert thresholds (now required by updateSettings)
            'alert_overcensus_pct' => $s->alert_overcensus_pct, 'alert_boarding_max' => $s->alert_boarding_max,
            'alert_readmit_rate_pct' => $s->alert_readmit_rate_pct, 'alert_deaths_delta_pct' => $s->alert_deaths_delta_pct,
            // Phase 4 thresholds (now required by updateSettings)
            'idle_timeout_minutes' => $s->idle_timeout_minutes ?? 30, 'abs_timeout_minutes' => $s->abs_timeout_minutes ?? 0,
            'failed_login_notify_threshold' => $s->failed_login_notify_threshold ?? 5, 'dq_los_multiplier' => $s->dq_los_multiplier ?? 2,
        ])->assertRedirect();

        $this->assertDatabaseHas('setting_changes', [
            'field' => 'ward_beds', 'new_value' => '64', 'changed_by' => $admin->id,
        ]);
        // unchanged fields produce no history rows
        $this->assertSame(0, DB::table('setting_changes')->where('field', 'icu_beds')->count());
        $this->assertSame(64, (int) Setting::current()->ward_beds);
        // singleton stays a singleton (regression: guarded-id firstOrCreate minted a new row per call)
        $this->assertSame(1, DB::table('settings')->count());
    }
}
