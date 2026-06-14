<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\Notification;
use App\Models\Patient;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Phase 4 — Item 6: data-quality dashboard + daily-digest command. The five canary queries surface
 * dirty data; the command notifies admins once per run when anything needs review.
 */
class DataQualityTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'username' => 'dq_' . substr(md5(uniqid('', true)), 0, 8),
            'name' => 'DQ Admin', 'role' => User::ROLE_ADMIN, 'active' => 1, 'password' => 'secret12345',
        ]);
    }

    private function patient(): Patient
    {
        return Patient::create(['mrn' => (string) random_int(100000, 999999), 'name' => 'DQ Pt', 'age' => 50, 'gender' => 'Male']);
    }

    private function assertProp(string $prop, \Closure $assert): void
    {
        $this->actingAs($this->admin())->get('/data-quality')
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Admin/DataQuality')->where($prop, $assert));
    }

    public function test_over_los_returns_qualifying_admissions(): void
    {
        $longLos = (int) Setting::current()->long_los;
        $mult = (int) Setting::current()->dq_los_multiplier;
        $a = Admission::create(['patient_id' => $this->patient()->id, 'current_location' => 'Ward', 'is_longterm' => 0,
            'admit_date' => now()->subDays($longLos * $mult + 1)->toDateString()]);

        $this->assertProp('overLos', fn ($rows) => collect($rows)->pluck('id')->contains($a->id));
    }

    public function test_over_los_excludes_longterm(): void
    {
        $longLos = (int) Setting::current()->long_los;
        $mult = (int) Setting::current()->dq_los_multiplier;
        $a = Admission::create(['patient_id' => $this->patient()->id, 'current_location' => 'Ward', 'is_longterm' => 1,
            'admit_date' => now()->subDays($longLos * $mult + 5)->toDateString()]);

        $this->assertProp('overLos', fn ($rows) => ! collect($rows)->pluck('id')->contains($a->id));
    }

    public function test_no_dx_returns_admission_without_diagnoses(): void
    {
        $a = Admission::create(['patient_id' => $this->patient()->id, 'current_location' => 'Ward',
            'admit_date' => now()->toDateString(), 'is_longterm' => 0]);

        $this->assertProp('noDx', fn ($rows) => collect($rows)->pluck('id')->contains($a->id));
    }

    public function test_bad_dates_flags_discharge_before_admit(): void
    {
        // The Phase 4 — Item 7 CHECK now PREVENTS new discharge-before-admit rows. To prove the
        // canary still surfaces such a row (legacy data predates the constraint), insert it with the
        // constraint momentarily dropped — simulating a row that slipped in before the migration.
        // ALTER TABLE is DDL (implicit commit) so it escapes RefreshDatabase's wrapping transaction;
        // the row is therefore deleted and the constraint re-added by hand before the test returns,
        // leaving a clean schema for sibling tests.
        DB::statement('ALTER TABLE admissions DROP CONSTRAINT chk_discharge_gte_admit');
        $a = Admission::create(['patient_id' => $this->patient()->id, 'current_location' => 'Ward',
            'admit_date' => now()->toDateString(), 'discharge_date' => now()->subDays(3)->toDateString(),
            'outcome' => 'Alive', 'transfer_type' => 'discharge from ward']);

        $this->assertProp('badDates', fn ($rows) => collect($rows)->pluck('id')->contains($a->id));

        $a->forceDelete();
        DB::statement('ALTER TABLE admissions ADD CONSTRAINT chk_discharge_gte_admit
            CHECK (discharge_date IS NULL OR admit_date IS NULL OR discharge_date >= admit_date)');
    }

    public function test_bad_dates_flags_future_admit(): void
    {
        $a = Admission::create(['patient_id' => $this->patient()->id, 'current_location' => 'Ward',
            'admit_date' => now()->addDay()->toDateString(), 'is_longterm' => 0]);

        $this->assertProp('badDates', fn ($rows) => collect($rows)->pluck('id')->contains($a->id));
    }

    public function test_double_open_detects_two_open_episodes(): void
    {
        $p = $this->patient();
        Admission::create(['patient_id' => $p->id, 'current_location' => 'Ward', 'admit_date' => now()->subDay()->toDateString()]);
        Admission::create(['patient_id' => $p->id, 'current_location' => 'ICU', 'admit_date' => now()->toDateString()]);

        $this->assertProp('doubleOpen', fn ($rows) => collect($rows)->pluck('mrn')->contains($p->mrn));
    }

    public function test_orphan_dx_flags_unknown_code_on_active_episode(): void
    {
        $a = Admission::create(['patient_id' => $this->patient()->id, 'current_location' => 'Ward', 'admit_date' => now()->toDateString()]);
        DB::table('admission_diagnoses')->insert(['admission_id' => $a->id, 'seq' => 1, 'icd10_code' => 'NOPE00',
            'created_at' => now(), 'updated_at' => now()]);

        $this->assertProp('orphanDx', fn ($rows) => collect($rows)->pluck('icd10_code')->contains('NOPE00'));
    }

    public function test_data_quality_denied_to_non_admin(): void
    {
        $consultant = User::create(['username' => 'dqc_' . substr(md5(uniqid('', true)), 0, 6),
            'name' => 'DQ Cons', 'role' => User::ROLE_CONSULTANT, 'active' => 1, 'password' => 'secret12345']);
        $this->actingAs($consultant)->get('/data-quality')->assertForbidden();
    }

    public function test_dq_notify_command_creates_notification_for_admins(): void
    {
        $admin = $this->admin();
        // one qualifying row (active episode with no diagnosis)
        Admission::create(['patient_id' => $this->patient()->id, 'current_location' => 'Ward',
            'admit_date' => now()->toDateString(), 'is_longterm' => 0]);

        $this->artisan('dq:notify')->assertSuccessful();

        $this->assertDatabaseHas('notifications', ['user_id' => $admin->id, 'type' => 'dq.daily_report']);
    }

    public function test_dq_notify_command_silent_when_clean(): void
    {
        $admin = $this->admin();
        // no admissions => nothing to flag
        $this->artisan('dq:notify')->assertSuccessful();
        $this->assertDatabaseMissing('notifications', ['user_id' => $admin->id, 'type' => 'dq.daily_report']);
    }
}
