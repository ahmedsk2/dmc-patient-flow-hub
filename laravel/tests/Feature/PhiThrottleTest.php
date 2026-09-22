<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\Patient;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Engineering batch, group g1-throttle — PERF-08 (per-user 'phi' / 'phi-export' rate limiters on
 * the PHI-bearing routes) + the I18N-05 remnant (the 'auth' limiter's identity key must fold case
 * the same multibyte-safe way the login lookups themselves do).
 *
 * Every test below uses its own freshly-created user (auto-increment id, never reused across a
 * RefreshDatabase-wrapped test) or a randomised identity base, so the array-cache-backed
 * RateLimiter state (CACHE_STORE=array in phpunit.xml, which persists across test METHODS within
 * one process) never bleeds between tests — see CLAUDE.md / the group brief's "avoid cross-test
 * bleed" note.
 */
class PhiThrottleTest extends TestCase
{
    use RefreshDatabase;

    private function user(int $role = User::ROLE_CONSULTANT, array $extra = []): User
    {
        return User::create(array_merge([
            'username' => 'pt_'.substr(md5(uniqid('', true)), 0, 10),
            'name' => 'Throttle Test User', 'password' => 'secret12345', 'role' => $role, 'active' => 1,
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
        ], $extra));
    }

    private function admin(): User
    {
        return $this->user(User::ROLE_ADMIN);
    }

    // ---- I18N-05: the auth-throttle identity key must be multibyte-case-fold-safe -----------------

    public function test_auth_throttle_identity_key_folds_non_ascii_case_like_the_login_lookups(): void
    {
        // Randomised ASCII base keeps this test's bucket isolated from every other test that also
        // hammers /login; the accented pair is what actually exercises mb_strtolower() vs strtolower().
        $base = 'lg'.substr(md5(uniqid('', true)), 0, 10);
        $upper = $base.'É';   // U+00C9 — two-byte UTF-8, outside strtolower()'s A-Z range
        $lower = $base.'é';   // U+00E9 — its mb_strtolower() fold

        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['username' => $upper, 'password' => 'wrong-'.$i]);
        }
        // the 6th attempt against the SAME spelling is the known-good throttle behaviour
        $this->assertSame(429, $this->post('/login', ['username' => $upper, 'password' => 'wrong'])->getStatusCode(),
            'baseline: a 6th attempt against the same identity is throttled');

        // A case-only variant, differing solely in a non-ASCII letter's case, must land in the SAME
        // bucket (already exhausted above) rather than get its own fresh 5 attempts — that gap is
        // exactly what byte-wise strtolower() left open (I18N-05).
        $this->assertSame(429, $this->post('/login', ['username' => $lower, 'password' => 'wrong'])->getStatusCode(),
            'a non-ASCII case variant of the same identity must share the auth throttle bucket');
    }

    // ---- PERF-08: the general 'phi' page/JSON-poll limiter -----------------------------------------

    public function test_phi_limiter_blocks_the_241st_request_in_a_minute_for_one_user(): void
    {
        $user = $this->user();
        $this->actingAs($user);

        // q=x is 1 char — AdmissionsController::icd10() returns [] before touching the DB, so this
        // loop exercises the full auth+throttle middleware stack without 240 query round-trips.
        for ($i = 0; $i < 240; $i++) {
            $status = $this->getJson('/api/icd10?q=x')->getStatusCode();
            $this->assertNotSame(429, $status, "request #{$i} is within the 240/min allowance and must not be throttled");
        }

        $this->assertSame(429, $this->getJson('/api/icd10?q=x')->getStatusCode(),
            'the 241st PHI-route request inside a minute must be throttled');
    }

    public function test_phi_limiter_is_scoped_per_user_not_shared(): void
    {
        $exhausted = $this->user();
        $other = $this->user();

        $this->actingAs($exhausted);
        for ($i = 0; $i < 240; $i++) {
            $this->getJson('/api/icd10?q=x');
        }
        $this->assertSame(429, $this->getJson('/api/icd10?q=x')->getStatusCode(), 'sanity: this user is now throttled');

        // a second, distinct user — same test process/IP — must have an untouched bucket of their own
        $this->actingAs($other);
        $this->assertNotSame(429, $this->getJson('/api/icd10?q=x')->getStatusCode(),
            'a different user must not be affected by another user exhausting their PHI bucket');
    }

    public function test_the_admission_detail_read_shares_the_phi_bucket(): void
    {
        // The Modify modal's full-demographics read (GET /admissions/{id}/edit) was the one PHI detail
        // read the first pass left unthrottled; it must drain the same per-user bucket as the pages.
        $admin = $this->admin();
        $patient = Patient::create(['mrn' => (string) random_int(10000000, 99999999), 'name' => 'Throttle Probe']);
        $admission = Admission::create([
            'patient_id' => $patient->id, 'admit_date' => now()->toDateString(), 'current_location' => 'Ward',
        ]);

        $this->actingAs($admin);
        $this->assertNotSame(429, $this->getJson("/admissions/{$admission->id}/edit")->getStatusCode());
        for ($i = 0; $i < 239; $i++) {
            $this->getJson('/api/icd10?q=x');
        }
        $this->assertSame(429, $this->getJson("/admissions/{$admission->id}/edit")->getStatusCode(),
            'the 241st PHI read in a minute — here the admission detail — must be throttled');
    }

    public function test_ordinary_navigation_across_phi_pages_is_never_throttled(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);

        // A realistic single-session round of clinical navigation — nowhere near 240/min.
        foreach (['/', '/patients', '/active-list', '/handovers', '/consultations', '/registry', '/statistics', '/reports'] as $url) {
            $status = $this->get($url)->getStatusCode();
            $this->assertNotSame(429, $status, "{$url} must not be throttled during ordinary navigation");
        }
    }

    // ---- PERF-08: the stricter 'phi-export' limiter, and that it bites BEFORE the general one ------

    public function test_phi_export_limiter_blocks_the_21st_export_in_a_minute(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);

        for ($i = 0; $i < 20; $i++) {
            $status = $this->get('/statistics/export')->getStatusCode();
            $this->assertNotSame(429, $status, "export #{$i} is within the 20/min allowance and must not be throttled");
        }

        $this->assertSame(429, $this->get('/statistics/export')->getStatusCode(),
            'the 21st export inside a minute must be throttled');
    }

    public function test_exports_hit_the_tighter_limit_first_leaving_ordinary_pages_unaffected(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);

        // Exhaust the 20/min export bucket for this admin...
        for ($i = 0; $i < 21; $i++) {
            $this->get('/statistics/export');
        }
        $this->assertSame(429, $this->get('/statistics/export')->getStatusCode(), 'sanity: exports are now throttled');

        // ...while the SAME admin's ordinary page views are a completely separate 'phi' bucket
        // (240/min, nowhere near exhausted by 21 requests) and are unaffected.
        $this->assertNotSame(429, $this->get('/statistics')->getStatusCode(),
            'exhausting the export bucket must not throttle the page-view bucket for the same user');
    }

    // ---- 429 rendering: a real page (not an XHR poll) must not go blank --------------------------

    public function test_a_throttled_page_navigation_renders_the_branded_error_page_not_a_blank_response(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);

        for ($i = 0; $i < 240; $i++) {
            $this->getJson('/api/icd10?q=x');   // shares the same per-user 'phi' bucket as /patients
        }

        $response = $this->get('/patients');
        $response->assertStatus(429);
        $response->assertSee('Too many requests');
        $this->assertNotSame('', trim($response->getContent()), '429 body must not be blank');
    }
}
