<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\Patient;
use App\Models\Setting;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * I18N-02 (prod-ready 2026-09-03): "today" must come from the APP clock, never from MySQL's
 * CURDATE(). Production runs the database host in UTC while the app runs Asia/Riyadh (+03:00), so
 * between 00:00 and 03:00 local CURDATE() is still YESTERDAY — exactly when the night shift works.
 *
 * Tests\TestCase aligns the two clocks for every other test (SET time_zone = PHP offset). This test
 * deliberately re-creates the production skew (DB session pinned to UTC, app clock frozen at 01:30
 * Riyadh on a date far from the real one) and pins the three pages that used to read the DB's day.
 *
 * The fix binds PHP's date as a query parameter. It is NOT a session time_zone pin: the schema is
 * full of TIMESTAMP columns, which MySQL re-interprets through the session zone, so pinning would
 * silently shift every stored created_at / assigned_at / mfa_enrolled_at by three hours.
 */
class AppClockDayBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private const FROZEN = '2030-01-01 01:30:00';   // Riyadh; UTC is still 2029-12-31 22:30

    private string $savedTz;

    protected function setUp(): void
    {
        parent::setUp();
        $this->savedTz = date_default_timezone_get();
        date_default_timezone_set('Asia/Riyadh');
        config(['app.timezone' => 'Asia/Riyadh']);
        Carbon::setTestNow(Carbon::parse(self::FROZEN, 'Asia/Riyadh'));
        DB::statement("SET time_zone = '+00:00'");   // the production skew: DB session day = UTC day
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        date_default_timezone_set($this->savedTz);
        config(['app.timezone' => $this->savedTz]);
        DB::statement("SET time_zone = '".now()->format('P')."'");   // hand the session back aligned
        parent::tearDown();
    }

    private function admin(): User
    {
        return User::create([
            'username' => 'clk_'.substr(md5(uniqid('', true)), 0, 8),
            'name' => 'Clock Admin', 'role' => User::ROLE_ADMIN, 'active' => 1, 'password' => 'secret12345',
            'email' => 'clk_'.substr(md5(uniqid('', true)), 0, 6).'@example.test', 'email_verified_at' => now(),
            'pass_exp_date' => now()->toDateString(),
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
        ]);
    }

    private function admission(array $attrs): Admission
    {
        $p = Patient::create(['mrn' => (string) random_int(100000, 999999), 'name' => 'Clock Pt', 'age' => 40, 'gender' => 'Male']);

        return Admission::create(['patient_id' => $p->id, 'current_location' => 'Ward', 'is_longterm' => 0] + $attrs);
    }

    public function test_the_skew_is_real_in_this_test(): void
    {
        // guard: if this ever stops holding, the other assertions prove nothing
        $dbDay = DB::selectOne('SELECT CURDATE() d')->d;
        $this->assertNotSame(Carbon::today()->toDateString(), $dbDay, 'DB day must differ from the app day here');
    }

    public function test_data_quality_does_not_flag_a_same_day_admission_as_future_dated(): void
    {
        $a = $this->admission(['admit_date' => Carbon::today()->toDateString()]);   // admitted "today" (app day)

        $this->actingAs($this->admin())->get('/data-quality')
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Admin/DataQuality')
                ->where('badDates', fn ($rows) => ! collect($rows)->pluck('id')->contains($a->id)));
    }

    public function test_data_quality_los_is_measured_from_the_app_day(): void
    {
        $threshold = (int) Setting::current()->long_los * max(1, (int) Setting::current()->dq_los_multiplier);
        $a = $this->admission(['admit_date' => Carbon::today()->subDays($threshold + 1)->toDateString()]);

        $this->actingAs($this->admin())->get('/data-quality')
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Admin/DataQuality')
                ->where('overLos', function ($rows) use ($a, $threshold) {
                    $row = collect($rows)->firstWhere('id', $a->id);

                    return $row !== null && (int) $row['los'] === $threshold + 1;
                }));
    }

    public function test_dashboard_boarding_delay_counts_from_the_app_day(): void
    {
        $a = $this->admission([
            'admit_date' => Carbon::today()->subDays(3)->toDateString(),
            'medical_discharge_date' => Carbon::today()->toDateString(),   // cleared today → delay 0
        ]);

        $this->actingAs($this->admin())->get('/')
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Dashboard')
                ->where('boardingWorklist', function ($rows) use ($a) {
                    $row = collect($rows)->firstWhere('id', $a->id);

                    return $row !== null && (int) $row['delay_days'] === 0;
                }));
    }

    /**
     * Consistency smoke check only — NOT a day-boundary proof. In an ORDER BY, the reference date
     * cancels out of every pairwise DATEDIFF comparison (DATEDIFF(ref,a) − DATEDIFF(ref,b) = b − a),
     * so this ordering is identical whether "today" is bound from PHP or read from CURDATE(). The
     * Registry change is therefore consistency with the other call sites, and this test pins that
     * the bound-parameter form executes and orders correctly. The two pages above are the proofs.
     */
    public function test_registry_los_sort_still_orders_open_admissions_with_the_bound_parameter(): void
    {
        $long = $this->admission(['admit_date' => Carbon::today()->subDays(10)->toDateString()]);
        $short = $this->admission(['admit_date' => Carbon::today()->subDays(2)->toDateString()]);

        $this->actingAs($this->admin())->post('/registry', ['sort' => 'los', 'dir' => 'desc'])
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('results.data', function ($rows) use ($long, $short) {
                    $ids = collect($rows)->pluck('id')->values();
                    $iLong = $ids->search($long->id);
                    $iShort = $ids->search($short->id);

                    return $iLong !== false && $iShort !== false && $iLong < $iShort;
                }));
    }
}
