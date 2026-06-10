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
            'outcome' => 'Alive', 'medical_discharge_date' => now()->toDateString(), 'delay_reason' => 'bed not freed',
        ])->assertRedirect();
        $a->refresh();
        $this->assertNotNull($a->medical_discharge_date);
        $this->assertNull($a->discharge_date, 'medical discharge must NOT close the episode');

        // phase 2 - complete discharge: closes the file
        $this->actingAs($admin)->post("/admissions/{$a->id}/complete-discharge", [
            'discharge_date' => now()->toDateString(),
        ])->assertRedirect();
        $a->refresh();
        $this->assertNotNull($a->discharge_date);
        $this->assertSame('discharge from ward', $a->transfer_type);

        // a second complete-discharge is rejected (already discharged)
        $this->actingAs($admin)->post("/admissions/{$a->id}/complete-discharge", [
            'discharge_date' => now()->toDateString(),
        ]);
        $this->assertSame(1, Admission::whereNotNull('discharge_date')->count());

        // reverse (admin) returns the patient to active
        $this->actingAs($admin)->post("/admissions/{$a->id}/reverse-discharge")->assertRedirect();
        $a->refresh();
        $this->assertNull($a->discharge_date);
        $this->assertNull($a->medical_discharge_date);
    }

    public function test_icu_discharge_is_single_step(): void
    {
        $admin = $this->admin();
        $a = $this->admission(['current_location' => 'ICU']);

        $this->actingAs($admin)->post("/admissions/{$a->id}/icu-discharge", [
            'outcome' => 'Alive', 'discharge_date' => now()->toDateString(),
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

    public function test_settings_change_is_recorded_in_history(): void
    {
        $admin = $this->admin();
        $s = Setting::current();

        $this->actingAs($admin)->put('/control/settings', [
            'min_hospitalist' => $s->min_hospitalist, 'max_hospitalist' => $s->max_hospitalist,
            'min_subs' => $s->min_subs, 'max_subs' => $s->max_subs,
            'short_los' => $s->short_los, 'long_los' => $s->long_los,
            'ward_beds' => 64, 'icu_beds' => $s->icu_beds,
            'mfa_enforcement' => $s->mfa_enforcement ?? 0,
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
