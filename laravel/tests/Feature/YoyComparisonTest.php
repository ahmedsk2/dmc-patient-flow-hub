<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\Patient;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** Phase 3 — §3.5: year-over-year / prior-period comparison overlay. */
class YoyComparisonTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['username' => 'yoy_admin_' . substr(md5(uniqid('', true)), 0, 6),
            'name' => 'YOY Admin', 'password' => 'secret12345', 'role' => User::ROLE_ADMIN, 'active' => 1,
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now()]);
    }

    private function admit(string $mrn, string $date): void
    {
        $p = Patient::create(['mrn' => $mrn, 'name' => "Pt {$mrn}"]);
        Admission::create(['patient_id' => $p->id, 'admit_date' => $date,
            'current_location' => 'Ward', 'is_longterm' => 0, 'is_new_assignment' => 0]);
    }

    public function test_compare_prior_year_returns_compare_data_prop(): void
    {
        $this->actingAs($this->admin())
            ->get('/statistics?from=2024-01-01&to=2024-03-31&compare=prior_year')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('compareData.range.from', '2023-01-01')
                ->where('compareData.range.to', '2023-03-31'));
    }

    public function test_compare_prior_period_range_is_equal_length(): void
    {
        $this->actingAs($this->admin())
            ->get('/statistics?from=2024-04-01&to=2024-06-30&compare=prior_period')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('compareData.range.from', '2024-01-01')
                ->where('compareData.range.to', '2024-03-31'));
    }

    public function test_compare_kpis_values_match_independent_query(): void
    {
        // 3 admissions in 2024-06, 2 in 2023-06 (same month, prior year)
        $this->admit('12000001', '2024-06-02');
        $this->admit('12000002', '2024-06-03');
        $this->admit('12000003', '2024-06-04');
        $this->admit('12000004', '2023-06-10');
        $this->admit('12000005', '2023-06-20');

        $this->actingAs($this->admin())
            ->get('/statistics?from=2024-06-01&to=2024-06-30&compare=prior_year')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('kpis.admissions', 3)
                ->where('compareData.kpis.admissions', 2));
    }

    public function test_compare_invalid_mode_rejected(): void
    {
        $this->actingAs($this->admin())
            ->get('/statistics?compare=next_year')->assertInvalid(['compare']);
    }

    public function test_no_compare_when_param_absent(): void
    {
        $this->actingAs($this->admin())
            ->get('/statistics?from=2024-06-01&to=2024-06-30')
            ->assertInertia(fn (AssertableInertia $p) => $p->where('compareData', null));
    }
}
