<?php

namespace Tests\Feature;

use App\Http\Controllers\ReportsController;
use App\Models\Admission;
use App\Models\Patient;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * g6-analytics — role/UX review 2026-09-24, section 2 #9/#10/#41 (Registry/Statistics/Reports).
 * Fixture idiom mirrors FinalSweepG2Test/Round5J1Test: users built inline, no factories.
 */
class UxReviewG6AnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(int $role = User::ROLE_CONSULTANT, array $extra = []): User
    {
        return User::create(array_merge([
            'username' => 'g6_'.substr(md5(uniqid('', true)), 0, 10),
            'name' => 'G6 User', 'password' => 'secret12345', 'role' => $role, 'active' => 1,
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
        ], $extra));
    }

    private function admin(): User
    {
        return $this->user(User::ROLE_ADMIN);
    }

    private function admission(array $overrides = [], ?Patient $patient = null): Admission
    {
        $p = $patient ?? Patient::create(['mrn' => (string) random_int(10000000, 99999999), 'name' => 'G6 Patient']);

        return Admission::create(array_merge([
            'patient_id' => $p->id, 'admit_date' => '2024-06-02',
            'current_location' => 'Ward', 'is_longterm' => 0, 'is_new_assignment' => 0,
        ], $overrides));
    }

    // ---- #9: Registry's Ward→ICU close gets its own label, not "Out-dept transfer" ----------------

    public function test_registry_labels_a_ward_to_icu_close_distinctly_from_a_real_out_dept_transfer(): void
    {
        // both write transfer_type='other transfer' (PatientActionController::transferLocation /
        // transferExternal) — only discharge_to tells them apart, which the label must now use.
        $icuMove = $this->admission(['admit_date' => '2024-06-10', 'discharge_date' => '2024-06-12',
            'transfer_type' => 'other transfer', 'discharge_to' => 'Intensive Care (ICU)']);
        $realExternal = $this->admission(['admit_date' => '2024-06-10', 'discharge_date' => '2024-06-12',
            'transfer_type' => 'other transfer', 'discharge_to' => 'Psychiatry (external)']);

        $this->actingAs($this->admin())
            ->get('/registry?from=2024-06-01&to=2024-06-30')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('results.total', 2)
                // newest-admit-first with same admit_date ties on id desc: realExternal (later id) first
                ->where('results.data.0.id', $realExternal->id)
                ->where('results.data.0.transfer_label', 'Out-dept transfer')
                ->where('results.data.1.id', $icuMove->id)
                ->where('results.data.1.transfer_label', 'Transferred to ICU'));
    }

    public function test_registry_out_dept_transfer_label_untouched_when_destination_is_not_icu(): void
    {
        // regression guard: a transfer_type='other transfer' row with no ICU destination must keep
        // the original "Out-dept transfer" label (mirrors FinalSweepG2's existing 3-type coverage).
        $this->admission(['admit_date' => '2024-06-10', 'discharge_date' => '2024-06-12',
            'transfer_type' => 'other transfer']);

        $this->actingAs($this->admin())
            ->get('/registry?from=2024-06-01&to=2024-06-30')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('results.data.0.transfer_label', 'Out-dept transfer'));
    }

    // ---- #41: Statistics flags a From-after-To range it silently swapped ---------------------------

    public function test_statistics_flags_a_from_after_to_range_as_swapped(): void
    {
        $this->actingAs($this->admin())
            ->get('/statistics?from=2024-06-30&to=2024-06-01')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('rangeSwapped', true)
                ->where('range.from', '2024-06-01')
                ->where('range.to', '2024-06-30'));
    }

    public function test_statistics_does_not_flag_a_normal_range(): void
    {
        $this->actingAs($this->admin())
            ->get('/statistics?from=2024-06-01&to=2024-06-30')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('rangeSwapped', false)
                ->where('range.from', '2024-06-01')
                ->where('range.to', '2024-06-30'));
    }

    // ---- #10: the current month's report doesn't paint tomorrow as a real zero day -----------------

    public function test_monthly_report_screen_marks_future_days_and_reports_data_through_today(): void
    {
        Carbon::setTestNow('2026-06-15 10:00:00');
        // one real admission on the 3rd, well before "today"
        $this->admission(['admit_date' => '2026-06-03', 'current_location' => 'Ward']);

        $this->actingAs($this->admin())
            ->get('/reports/monthly?year=2026&month=6')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('asOf', '2026-06-15')
                // day 3 already happened: real (non-null) counts, not flagged future
                ->where('days.2.day', 3)->where('days.2.future', false)->where('days.2.admissions', 1)
                // day 15 is "today" itself — still counted, not future
                ->where('days.14.day', 15)->where('days.14.future', false)
                // day 16 is tomorrow relative to the fixed clock — future, counts withheld (null)
                ->where('days.15.day', 16)->where('days.15.future', true)
                ->where('days.15.admissions', null)->where('days.15.discharges', null)
                // the totals for the month are unaffected (future days always contributed 0 anyway)
                ->where('totals.admissions', 1));
    }

    public function test_monthly_report_screen_has_no_future_days_for_a_fully_elapsed_month(): void
    {
        Carbon::setTestNow('2026-07-10 10:00:00');

        $this->actingAs($this->admin())
            ->get('/reports/monthly?year=2026&month=6')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('days.29.future', false)   // June 30 — fully in the past
                ->where('asOf', '2026-07-10'));
    }

    public function test_monthly_booklet_truncates_the_daily_chart_at_today_for_the_in_progress_month(): void
    {
        Carbon::setTestNow('2026-06-15 10:00:00');
        $this->admission(['admit_date' => '2026-01-05', 'current_location' => 'Ward']);   // gives January real data

        $data = app(ReportsController::class)->gatherBooklet(2026);

        $this->assertSame('2026-06-15', $data['asOf']);
        $june = collect($data['months'])->firstWhere('m', 6);
        $this->assertNotNull($june, 'June must be included — it has started');
        $this->assertTrue($june['partial'], 'June is still in progress');
        // the daily-overview series stops at today (15 days), not the full 30-day month
        $this->assertCount(15, $june['days']['labels']);
        $this->assertSame('15', end($june['days']['labels']));

        $january = collect($data['months'])->firstWhere('m', 1);
        $this->assertFalse($january['partial'], 'January is fully elapsed');
        $this->assertCount(31, $january['days']['labels']);

        // July has not started yet — still excluded entirely (legacy rule, unchanged by this fix)
        $this->assertNull(collect($data['months'])->firstWhere('m', 7));
    }
}
