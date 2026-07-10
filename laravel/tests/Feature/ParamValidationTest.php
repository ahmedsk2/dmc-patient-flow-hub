<?php

namespace Tests\Feature;

use App\Support\Totp;

use App\Models\Admission;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** Phase 3 — §3.7: report year clamp/validation + registry filter validation + empty-state props. */
#[\PHPUnit\Framework\Attributes\Group('pdf')]
class ParamValidationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['username' => 'pv_admin_' . substr(md5(uniqid('', true)), 0, 6),
            'name' => 'PV Admin', 'password' => 'secret12345', 'role' => User::ROLE_ADMIN, 'active' => 1, 'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now()]);
    }

    private function admit(string $mrn, string $date): void
    {
        $p = Patient::create(['mrn' => $mrn, 'name' => "Pt {$mrn}"]);
        Admission::create(['patient_id' => $p->id, 'admit_date' => $date,
            'current_location' => 'Ward', 'is_longterm' => 0, 'is_new_assignment' => 0]);
    }

    public function test_reports_index_future_year_is_clamped_to_latest_available(): void
    {
        $this->admit('14000001', '2024-05-01');
        $this->actingAs($this->admin())->get('/reports?year=2030')
            ->assertInertia(fn (AssertableInertia $p) => $p->where('year', 2024));
    }

    public function test_reports_pdf_future_year_rejected(): void
    {
        // year+2 exceeds the now()->year+1 max
        $future = now()->year + 2;
        $this->actingAs($this->admin())->get("/reports/pdf?year={$future}")->assertInvalid(['year']);
    }

    public function test_reports_pdf_invalid_year_rejected(): void
    {
        $this->actingAs($this->admin())->get('/reports/pdf?year=abc')->assertInvalid(['year']);
    }

    public function test_registry_invalid_date_rejected(): void
    {
        $this->actingAs($this->admin())->get('/registry?from=notadate')->assertInvalid(['from']);
    }

    public function test_registry_invalid_age_rejected(): void
    {
        $this->actingAs($this->admin())->get('/registry?age_from=200')->assertInvalid(['age_from']);
    }

    public function test_reports_index_empty_state_prop_when_no_data(): void
    {
        // a year with no data — the Vue empty state is driven by totals.* === 0
        $this->actingAs($this->admin())->get('/reports?year=1990')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('totals.admissions', 0)
                ->where('totals.discharges', 0));
    }
}
