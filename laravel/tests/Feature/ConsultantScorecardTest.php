<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Phase 3 — §3.1: per-consultant scorecard PDF (auth, validation, value). */
#[\PHPUnit\Framework\Attributes\Group('pdf')]
class ConsultantScorecardTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['username' => 'sc_admin_' . substr(md5(uniqid('', true)), 0, 6),
            'name' => 'SC Admin', 'password' => 'secret12345', 'role' => User::ROLE_ADMIN, 'active' => 1]);
    }

    private function consultant(): User
    {
        return User::create(['username' => 'sc_cons_' . substr(md5(uniqid('', true)), 0, 6),
            'name' => 'Dr Cons', 'full_name' => 'Dr Consultant', 'password' => 'secret12345', 'role' => User::ROLE_CONSULTANT, 'active' => 1]);
    }

    public function test_consultant_scorecard_pdf_requires_admin(): void
    {
        $c = $this->consultant();
        $this->actingAs($c)->get("/reports/consultant/{$c->id}/pdf")->assertForbidden();
    }

    public function test_consultant_scorecard_pdf_returns_pdf_content_type(): void
    {
        $c = $this->consultant();
        $this->actingAs($this->admin())
            ->get("/reports/consultant/{$c->id}/pdf?from=2024-01-01&to=2024-12-31")
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_consultant_scorecard_pdf_404_for_nonexistent_user(): void
    {
        $this->actingAs($this->admin())->get('/reports/consultant/99999/pdf')->assertNotFound();
    }

    public function test_consultant_scorecard_pdf_invalid_dates_rejected(): void
    {
        $c = $this->consultant();
        $this->actingAs($this->admin())
            ->get("/reports/consultant/{$c->id}/pdf?from=notadate")->assertInvalid(['from']);
    }

    public function test_consultant_scorecard_reuses_physician_method_numbers(): void
    {
        $c = $this->consultant();
        $p1 = Patient::create(['mrn' => '80000001', 'name' => 'P1']);
        $p2 = Patient::create(['mrn' => '80000002', 'name' => 'P2']);
        $base = ['is_longterm' => 0, 'is_new_assignment' => 0, 'consultant_id' => $c->id, 'current_location' => 'Ward'];
        // 2 non-ICU admissions for this consultant in the range
        Admission::create([...$base, 'patient_id' => $p1->id, 'admit_date' => '2024-06-02', 'discharge_date' => '2024-06-07', 'outcome' => 'Alive', 'transfer_type' => 'discharge from ward']);
        Admission::create([...$base, 'patient_id' => $p2->id, 'admit_date' => '2024-06-03', 'current_location' => 'Ward']);

        // PDF download succeeds
        $this->actingAs($this->admin())
            ->get("/reports/consultant/{$c->id}/pdf?from=2024-06-01&to=2024-06-30")
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');

        // the physician() method (shared with Statistics) reports the same admissions count a
        // direct DB query gives — the scorecard cannot diverge from the screen drill-down
        $direct = (int) Admission::where('consultant_id', $c->id)
            ->whereBetween('admit_date', ['2024-06-01', '2024-06-30'])->whereRaw(Admission::NON_ICU_SQL)->count();
        $stats = app(\App\Http\Controllers\StatisticsController::class);
        $physician = $stats->physician($c->id, '2024-06-01', '2024-06-30', 3, 'month', []);
        $this->assertSame(2, $direct);
        $this->assertSame($direct, $physician['numbers']['admissions']);
    }
}
