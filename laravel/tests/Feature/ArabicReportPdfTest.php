<?php

namespace Tests\Feature;

use App\Http\Controllers\ReportsController;
use App\Models\Admission;
use App\Models\Patient;
use App\Models\User;
use App\Support\ArabicShaper;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use PHPUnit\Framework\Attributes\Group;
use ReflectionMethod;
use Tests\TestCase;

/**
 * I18N-06 — proves the ArabicShaper wiring survives contact with the real data path: an Arabic
 * consultant full_name flowing through ReportsController's actual query/gather code reaches each
 * template's HTML already shaped, and every one of the five PDF routes still renders a real PDF
 * once that data is Arabic instead of Latin (the concrete regression this whole item guards
 * against — a crash or a silently-unshaped name is what production would have shipped before).
 *
 * The HTML-level assertions (test_annual_pdf_html_contains_shaped_not_raw_arabic) render the exact
 * same view the controller renders, via the controller's own private gather() (through reflection,
 * so this does not hand-guess the data shape and drift from the real one), and check for presence
 * of a shaped Arabic Presentation Forms codepoint while asserting the RAW unshaped name is absent —
 * i.e. this fails if the shaping call is ever removed from the template, not just if it errors.
 */
#[Group('pdf')]
class ArabicReportPdfTest extends TestCase
{
    use RefreshDatabase;

    /** دكتور سارة — an Arabic consultant full name (dual-joining letters + one right-joining break). */
    private const ARABIC_CONSULTANT_NAME = 'دكتور سارة';

    private function admin(): User
    {
        return User::create(['username' => 'ar_admin_'.substr(md5(uniqid('', true)), 0, 6),
            'name' => 'AR Admin', 'password' => 'secret12345', 'role' => User::ROLE_ADMIN, 'active' => 1,
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now()]);
    }

    private function arabicConsultant(): User
    {
        return User::create(['username' => 'ar_cons_'.substr(md5(uniqid('', true)), 0, 6),
            'name' => 'ar_cons', 'full_name' => self::ARABIC_CONSULTANT_NAME,
            'password' => 'secret12345', 'role' => User::ROLE_CONSULTANT, 'active' => 1,
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now()]);
    }

    private function seedAdmission(User $consultant): void
    {
        $p = Patient::create(['mrn' => '92000001', 'name' => 'AR Patient']);
        Admission::create([
            'patient_id' => $p->id, 'consultant_id' => $consultant->id,
            'current_location' => 'Ward', 'is_longterm' => 0, 'is_new_assignment' => 0,
            'admit_date' => '2024-06-02', 'discharge_date' => '2024-06-07',
            'outcome' => 'Alive', 'transfer_type' => 'discharge from ward', 'discharge_to' => 'Home',
        ]);
    }

    // ---- route-level smoke: every PDF endpoint still renders real PDF bytes with Arabic data ----

    public function test_annual_pdf_renders_with_an_arabic_consultant_name(): void
    {
        $this->seedAdmission($this->arabicConsultant());

        $resp = $this->actingAs($this->admin())->get('/reports/pdf?year=2024');
        $resp->assertOk();
        $this->assertStringStartsWith('%PDF', $resp->getContent());
    }

    public function test_monthly_pdf_renders_with_an_arabic_consultant_name(): void
    {
        $this->seedAdmission($this->arabicConsultant());

        $resp = $this->actingAs($this->admin())->get('/reports/monthly/pdf?year=2024');
        $resp->assertOk();
        $this->assertStringStartsWith('%PDF', $resp->getContent());
    }

    public function test_statistics_pdf_renders_with_an_arabic_consultant_name(): void
    {
        $this->seedAdmission($this->arabicConsultant());

        $resp = $this->actingAs($this->admin())
            ->get('/statistics/export/pdf?from=2024-06-01&to=2024-06-30');
        $resp->assertOk();
        $this->assertStringStartsWith('%PDF', $resp->getContent());
    }

    public function test_governance_pdf_renders_with_an_arabic_consultant_name(): void
    {
        $this->seedAdmission($this->arabicConsultant());

        $resp = $this->actingAs($this->admin())
            ->get('/reports/governance/pdf?period_type=month&year=2024&month=6');
        $resp->assertOk();
        $this->assertStringStartsWith('%PDF', $resp->getContent());
    }

    public function test_consultant_scorecard_pdf_renders_for_an_arabic_named_consultant(): void
    {
        $consultant = $this->arabicConsultant();
        $this->seedAdmission($consultant);

        $resp = $this->actingAs($this->admin())
            ->get("/reports/consultant/{$consultant->id}/pdf?from=2024-01-01&to=2024-12-31");
        $resp->assertOk();
        $this->assertStringStartsWith('%PDF', $resp->getContent());
    }

    // ---- HTML-level: the shaping actually happened, not just "didn't crash" ----------------------

    /**
     * Calls ReportsController::gather() (private — the exact method the annual/monthly routes use)
     * via reflection so the fixture data shape can never drift from what the controller really
     * builds, then renders the SAME blade view directly to get raw HTML (bypassing dompdf, which
     * only matters for font/glyph rendering — already proven separately, see ArabicShaper's
     * docblock) and inspects the text dompdf would have been handed.
     */
    public function test_annual_pdf_html_contains_shaped_not_raw_arabic(): void
    {
        $this->seedAdmission($this->arabicConsultant());

        $controller = app(ReportsController::class);
        $gather = new ReflectionMethod($controller, 'gather');
        $gather->setAccessible(true);
        $data = $gather->invoke($controller, 2024);
        $data['generatedAt'] = now()->format('D, d M Y · H:i');

        $html = View::make('reports.annual-pdf', $data)->render();

        // the raw (unshaped) name must NOT appear literally in the output — if it does, the
        // template stopped calling ArabicShaper somewhere along the way
        $this->assertStringNotContainsString(self::ARABIC_CONSULTANT_NAME, $html);

        // a shaped presentation-form glyph for the first letter (DAL, right-joining: reflects a
        // shaping decision that only a real contextual pass would make) must be present, both in
        // the plain "Admissions by consultant" table row and in the hBar-chart-feeding array
        $dal_isolated = mb_chr(0xFEA9, 'UTF-8'); // ARABIC LETTER DAL ISOLATED FORM
        $dal_final = mb_chr(0xFEAA, 'UTF-8');    // ARABIC LETTER DAL FINAL FORM
        $this->assertTrue(
            str_contains($html, $dal_isolated) || str_contains($html, $dal_final),
            'expected a shaped DAL glyph (isolated or final form) from the consultant name in the rendered HTML'
        );

        // cross-check against the helper directly: whatever ArabicShaper produces for the fixture
        // name must be literally present in the page (proves the template didn't shape something
        // else or double/under-apply it)
        $shapedName = ArabicShaper::shape(self::ARABIC_CONSULTANT_NAME);
        $this->assertStringContainsString($shapedName, $html);

        // the static Arabic org-header phrase is shaped too, not just dynamic data
        $shapedOrg = ArabicShaper::shape('Eastern Health Cluster · تجمع الشرقية الصحي');
        $this->assertStringContainsString($shapedOrg, $html);
    }

    public function test_monthly_pdf_html_contains_shaped_consultant_name_in_the_chart_data(): void
    {
        $this->seedAdmission($this->arabicConsultant());

        $controller = app(ReportsController::class);
        $data = $controller->gatherBooklet(2024);
        $data['generatedAt'] = now()->format('D, d M Y · H:i');

        $html = View::make('reports.monthly-pdf', $data)->render();

        $this->assertStringNotContainsString(self::ARABIC_CONSULTANT_NAME, $html);

        // monthly-pdf only prints the consultant name inside the per-consultant-LOS hBar chart,
        // which ReportSvg::wrap() base64-embeds as a <img src="data:image/svg+xml;base64,..."> —
        // decode every such data URI and search the underlying SVG markup, since the raw HTML
        // itself never contains the name as literal text on this template.
        preg_match_all('/data:image\/svg\+xml;base64,([A-Za-z0-9+\/=]+)/', $html, $matches);
        $this->assertNotEmpty($matches[1], 'expected at least one base64 SVG data URI (the consultant LOS chart)');
        $decodedSvgs = implode("\n", array_map('base64_decode', $matches[1]));

        $shapedName = ArabicShaper::shape(self::ARABIC_CONSULTANT_NAME);
        $this->assertStringContainsString($shapedName, $decodedSvgs);
        $this->assertStringNotContainsString(self::ARABIC_CONSULTANT_NAME, $decodedSvgs);
    }

    // ---- regression: truncate-then-shape, not shape-then-truncate, at the hBar call sites -------

    /**
     * Title + given + father's + family/tribal name — a realistic, and long, Saudi full name:
     * 31 mb_strlen chars, well past ReportSvg::hBar()'s own internal 26-char truncation width.
     * Short enough consultant-name fixtures elsewhere in this file never reach that width, so they
     * cannot exercise the truncation path at all.
     */
    private const LONG_ARABIC_CONSULTANT_NAME = 'الدكتور محمد بن عبدالله العتيبي';

    private function longNameArabicConsultant(): User
    {
        return User::create(['username' => 'ar_long_'.substr(md5(uniqid('', true)), 0, 6),
            'name' => 'ar_long', 'full_name' => self::LONG_ARABIC_CONSULTANT_NAME,
            'password' => 'secret12345', 'role' => User::ROLE_CONSULTANT, 'active' => 1,
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now()]);
    }

    /**
     * ArabicShaper::shape() reverses an Arabic run so dompdf's left-to-right draw order produces
     * the correct right-to-left reading order. hBar() then truncates whatever string it is handed
     * to 26 chars via mb_strimwidth() — truncating an already-reversed string keeps the wrong
     * (tail) end of the name and puts the ellipsis on the wrong side. The fix truncates the RAW
     * logical name to hBar's width first, then shapes, mirroring the ArabicShaper::shape(Str::limit
     * (...)) pattern already used correctly for plain-HTML table cells in these same templates.
     */
    public function test_monthly_pdf_hbar_chart_truncates_the_raw_name_before_shaping(): void
    {
        $this->seedAdmission($this->longNameArabicConsultant());

        $controller = app(ReportsController::class);
        $data = $controller->gatherBooklet(2024);
        $data['generatedAt'] = now()->format('D, d M Y · H:i');

        $html = View::make('reports.monthly-pdf', $data)->render();

        preg_match_all('/data:image\/svg\+xml;base64,([A-Za-z0-9+\/=]+)/', $html, $matches);
        $this->assertNotEmpty($matches[1], 'expected at least one base64 SVG data URI (the consultant LOS chart)');
        $decodedSvgs = implode("\n", array_map('base64_decode', $matches[1]));

        $correct = ArabicShaper::shape(mb_strimwidth(self::LONG_ARABIC_CONSULTANT_NAME, 0, 26, '…'));
        $this->assertStringContainsString($correct, $decodedSvgs,
            'expected the truncate-then-shape result (informative title+given-name prefix kept)');

        $buggy = mb_strimwidth(ArabicShaper::shape(self::LONG_ARABIC_CONSULTANT_NAME), 0, 26, '…');
        $this->assertStringNotContainsString($buggy, $decodedSvgs,
            'the shape-then-truncate order must not reach the chart — it keeps the wrong end of the name');
    }

    public function test_annual_pdf_hbar_chart_truncates_the_raw_name_before_shaping(): void
    {
        $this->seedAdmission($this->longNameArabicConsultant());

        $controller = app(ReportsController::class);
        $gather = new ReflectionMethod($controller, 'gather');
        $gather->setAccessible(true);
        $data = $gather->invoke($controller, 2024);
        $data['generatedAt'] = now()->format('D, d M Y · H:i');

        $html = View::make('reports.annual-pdf', $data)->render();

        preg_match_all('/data:image\/svg\+xml;base64,([A-Za-z0-9+\/=]+)/', $html, $matches);
        $this->assertNotEmpty($matches[1], 'expected at least one base64 SVG data URI (the consultant LOS chart)');
        $decodedSvgs = implode("\n", array_map('base64_decode', $matches[1]));

        $correct = ArabicShaper::shape(mb_strimwidth(self::LONG_ARABIC_CONSULTANT_NAME, 0, 26, '…'));
        $this->assertStringContainsString($correct, $decodedSvgs,
            'expected the truncate-then-shape result (informative title+given-name prefix kept)');

        $buggy = mb_strimwidth(ArabicShaper::shape(self::LONG_ARABIC_CONSULTANT_NAME), 0, 26, '…');
        $this->assertStringNotContainsString($buggy, $decodedSvgs,
            'the shape-then-truncate order must not reach the chart — it keeps the wrong end of the name');
    }

    public function test_statistics_pdf_hbar_chart_truncates_the_raw_name_before_shaping(): void
    {
        // statistics-pdf's view data is assembled straight from StatisticsController::exportPdf()
        // (not this group's file); render the view directly with an equivalent minimal fixture so
        // this test stays inside this group's ownership of the blade template.
        $html = View::make('reports.statistics-pdf', [
            'from' => '2024-06-01', 'to' => '2024-06-30', 'interval' => 'day', 'readmitWindow' => 3,
            'kpis' => ['admissions' => 0, 'discharges' => 0, 'deaths' => 0, 'icuAdmissions' => 0,
                'consultations' => 0, 'signoffs' => 0, 'avgLos' => 0.0, 'readmissions' => 0, 'mortalityRate' => 0.0],
            'kpiGrid' => [],
            'perConsultant' => [['name' => self::LONG_ARABIC_CONSULTANT_NAME, 'admissions' => 0,
                'discharges' => 0, 'avgLos' => 4.2, 'readmits' => 0, 'consultations' => 0, 'signoffs' => 0]],
            'generatedAt' => now()->format('D, d M Y · H:i'),
        ])->render();

        preg_match_all('/data:image\/svg\+xml;base64,([A-Za-z0-9+\/=]+)/', $html, $matches);
        $this->assertNotEmpty($matches[1], 'expected at least one base64 SVG data URI (the consultant LOS chart)');
        $decodedSvgs = implode("\n", array_map('base64_decode', $matches[1]));

        $correct = ArabicShaper::shape(mb_strimwidth(self::LONG_ARABIC_CONSULTANT_NAME, 0, 26, '…'));
        $this->assertStringContainsString($correct, $decodedSvgs,
            'expected the truncate-then-shape result (informative title+given-name prefix kept)');

        $buggy = mb_strimwidth(ArabicShaper::shape(self::LONG_ARABIC_CONSULTANT_NAME), 0, 26, '…');
        $this->assertStringNotContainsString($buggy, $decodedSvgs,
            'the shape-then-truncate order must not reach the chart — it keeps the wrong end of the name');
    }
}
