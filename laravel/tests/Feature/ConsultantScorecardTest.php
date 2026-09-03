<?php

namespace Tests\Feature;

use App\Http\Controllers\StatisticsController;
use App\Models\Admission;
use App\Models\AuditLog;
use App\Models\Patient;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/** Phase 3 — §3.1: per-consultant scorecard PDF (auth, validation, value). */
#[Group('pdf')]
class ConsultantScorecardTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create(['username' => 'sc_admin_'.substr(md5(uniqid('', true)), 0, 6),
            'name' => 'SC Admin', 'password' => 'secret12345', 'role' => User::ROLE_ADMIN, 'active' => 1, 'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now()]);
    }

    private function consultant(): User
    {
        return User::create(['username' => 'sc_cons_'.substr(md5(uniqid('', true)), 0, 6),
            'name' => 'Dr Cons', 'full_name' => 'Dr Consultant', 'password' => 'secret12345', 'role' => User::ROLE_CONSULTANT, 'active' => 1, 'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now()]);
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
        $stats = app(StatisticsController::class);
        $physician = $stats->physician($c->id, '2024-06-01', '2024-06-30', 3, 'month', []);
        $this->assertSame(2, $direct);
        $this->assertSame($direct, $physician['numbers']['admissions']);
    }

    // ---- prod-ready G1: break-glass audit row on every consultant-scorecard export --------------

    public function test_consultant_scorecard_pdf_writes_one_audit_row(): void
    {
        $c = $this->consultant();
        $this->actingAs($this->admin())
            ->get("/reports/consultant/{$c->id}/pdf?from=2024-01-01&to=2024-12-31")->assertOk();

        $this->assertSame(1, AuditLog::where('action', 'report.pdf.consultant')->count());
        $row = AuditLog::where('action', 'report.pdf.consultant')->first();
        $this->assertSame('report', $row->entity_type);
        $this->assertSame($c->id, $row->details['consultant_id']);
        $this->assertSame('2024-01-01', $row->details['from']);
        $this->assertSame('2024-12-31', $row->details['to']);
    }

    // ---- DATA-CLASSIFICATION.md §4/§6: aggregate export carries a CONFIDENTIAL- filename ---------

    public function test_consultant_scorecard_pdf_filename_has_confidential_prefix(): void
    {
        $c = $this->consultant();
        $this->actingAs($this->admin())
            ->get("/reports/consultant/{$c->id}/pdf?from=2024-01-01&to=2024-12-31")
            ->assertDownload("CONFIDENTIAL-scorecard-{$c->id}-2024-01-01-2024-12-31.pdf");
    }

    /**
     * The consultant-pdf template carries ONE fixed-position classification footer (repeats on
     * every dompdf page) positioned before the first page div — not duplicated per-page.
     */
    public function test_consultant_pdf_template_carries_confidential_classification_footer(): void
    {
        $html = file_get_contents(resource_path('views/reports/consultant-pdf.blade.php'));
        $this->assertStringContainsString('CONFIDENTIAL — Internal use / خاص — للاستخدام الداخلي', $html);
        $this->assertStringContainsString('position: fixed', $html);
        $this->assertLessThan(
            strpos($html, '<div class="page'),
            strpos($html, '<div class="classification-foot">'),
            'the classification footer must sit outside/above the per-page divs so dompdf repeats it on every page'
        );
    }
}
