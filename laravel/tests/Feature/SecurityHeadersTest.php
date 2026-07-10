<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Prod-readiness closeout, Item 1: SecurityHeaders middleware (docs/superpowers/specs/
 * 2026-07-10-prod-readiness-headers-ci-brandsolid-design.md). Covers: the per-request CSP nonce
 * (shared to Blade, must match the value embedded in the enforced/reported policy), the three
 * CSP_MODE behaviours (enforce/report/off), the always-on static headers, HSTS gated on
 * request->secure() (never over plain HTTP), and Cache-Control: no-store on authenticated (not
 * guest) responses.
 */
class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'username' => 'sh_' . substr(md5(uniqid('', true)), 0, 10),
            'name' => 'SH Admin', 'password' => 'secret12345', 'role' => User::ROLE_ADMIN, 'active' => 1,
        ]);
    }

    public function test_authenticated_response_carries_the_full_header_set(): void
    {
        $response = $this->actingAs($this->admin())->get('/');

        $response->assertOk();

        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertNotNull($csp, 'enforce is the default CSP_MODE');
        $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-[A-Za-z0-9+\/=]+'/", $csp);

        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'same-origin');
        $response->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        $this->assertStringContainsString(
            'no-store',
            (string) $response->headers->get('Cache-Control'),
            'authenticated PHI pages must not persist in a shared-workstation cache'
        );
    }

    public function test_inline_theme_script_nonce_matches_the_csp_header_nonce(): void
    {
        $response = $this->actingAs($this->admin())->get('/');
        $response->assertOk();

        $csp = (string) $response->headers->get('Content-Security-Policy');
        preg_match("/'nonce-([A-Za-z0-9+\/=]+)'/", $csp, $headerMatch);
        $this->assertNotEmpty($headerMatch, 'CSP header must carry a nonce');

        $html = $response->getContent();
        preg_match('/<script nonce="([^"]+)">/', $html, $scriptMatch);
        $this->assertNotEmpty($scriptMatch, 'the theme-bootstrap inline script must carry the nonce attribute');

        $this->assertSame($headerMatch[1], $scriptMatch[1], 'the header nonce and the rendered script nonce must be identical');
    }

    public function test_guest_login_page_gets_headers_without_no_store_or_hsts(): void
    {
        $response = $this->get('/login');

        $response->assertOk();
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->assertStringNotContainsString(
            'no-store',
            (string) $response->headers->get('Cache-Control'),
            'guest pages keep the framework default Cache-Control'
        );
        $this->assertNull($response->headers->get('Strict-Transport-Security'));
    }

    public function test_no_hsts_over_plain_http(): void
    {
        $response = $this->actingAs($this->admin())->get('/');

        $this->assertNull($response->headers->get('Strict-Transport-Security'), 'test requests are http, never secure()');
    }

    public function test_report_mode_sends_report_only_header_and_omits_the_enforcing_one(): void
    {
        config(['security.csp_mode' => 'report']);

        $response = $this->actingAs($this->admin())->get('/');

        $this->assertNull($response->headers->get('Content-Security-Policy'));
        $this->assertNotNull($response->headers->get('Content-Security-Policy-Report-Only'));
    }

    public function test_off_mode_sends_no_csp_header_at_all_but_keeps_the_static_ones(): void
    {
        config(['security.csp_mode' => 'off']);

        $response = $this->actingAs($this->admin())->get('/');

        $this->assertNull($response->headers->get('Content-Security-Policy'));
        $this->assertNull($response->headers->get('Content-Security-Policy-Report-Only'));
        $response->assertHeader('X-Frame-Options', 'DENY');
    }

    public function test_policy_points_violation_reports_at_the_sink(): void
    {
        $response = $this->actingAs($this->admin())->get('/');

        $csp = (string) $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString('report-uri /csp-report', $csp);
        $this->assertStringContainsString('report-to csp-endpoint', $csp);
        $response->assertHeader('Reporting-Endpoints', 'csp-endpoint="/csp-report"');
    }

    public function test_csp_report_sink_accepts_a_browser_report_without_csrf_and_logs_it(): void
    {
        \Illuminate\Support\Facades\Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn ($message, $context) => $message === 'CSP violation reported'
                && ($context['violated-directive'] ?? null) === 'script-src'
                && ($context['blocked-uri'] ?? null) === 'https://evil.example/x.js');

        // raw JSON body, legacy report-uri envelope, NO CSRF token, NO auth — exactly as a browser posts it
        $response = $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/csp-report'], json_encode([
            'csp-report' => [
                'document-uri' => 'http://localhost/patients',
                'violated-directive' => 'script-src',
                'blocked-uri' => 'https://evil.example/x.js',
            ],
        ]));

        $response->assertNoContent();
    }

    public function test_csp_report_sink_never_errors_on_garbage(): void
    {
        $response = $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/csp-report'], 'not json at all {{{');

        $response->assertNoContent();
    }
}
