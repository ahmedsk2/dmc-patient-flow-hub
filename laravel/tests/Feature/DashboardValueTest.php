<?php

namespace Tests\Feature;

use App\Models\Consultation;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Phase-0 Item 7: the dashboard consultations-vs-sign-offs 6-month chart is built from two grouped
 * DATE_FORMAT('%Y-%m') queries zipped in PHP (replacing a 12-query month loop). This value test
 * pins the displayed numbers against the seeded DB so the perf refactor cannot change them.
 * Index 5 = current month, index 4 = previous month (6 labels, M-5 .. M).
 */
class DashboardValueTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'username' => 'dv_'.substr(md5(uniqid('', true)), 0, 10),
            'name' => 'DV Admin', 'password' => 'secret12345', 'role' => User::ROLE_ADMIN, 'active' => 1,
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
        ]);
    }

    private function consultation(array $overrides = []): Consultation
    {
        return Consultation::create(array_merge([
            'patient_name' => 'DV Patient', 'mrn' => (string) random_int(10000000, 99999999),
            'consultation_date' => Carbon::today()->toDateString(),
        ], $overrides));
    }

    public function test_consultations_chart_matches_db(): void
    {
        $thisMonth = Carbon::today();
        $lastMonth = Carbon::today()->subMonthNoOverflow()->startOfMonth();

        // 3 consultations this month
        $this->consultation(['consultation_date' => $thisMonth->copy()->startOfMonth()->toDateString()]);
        $this->consultation(['consultation_date' => $thisMonth->copy()->startOfMonth()->toDateString()]);
        $this->consultation(['consultation_date' => $thisMonth->toDateString()]);

        // 2 consultations last month, one of them signed off last month
        $this->consultation(['consultation_date' => $lastMonth->toDateString()]);
        $this->consultation([
            'consultation_date' => $lastMonth->toDateString(),
            'signoff_date' => $lastMonth->toDateString(),
        ]);

        $this->actingAs($this->admin())->get('/')->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->component('Dashboard', false)
                ->where('consults.new.5', 3)       // current month
                ->where('consults.new.4', 2)       // previous month
                ->where('consults.signed.4', 1));  // signed off last month
    }
}
