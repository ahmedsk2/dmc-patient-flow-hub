<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\Consultation;
use App\Models\Patient;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Wave 1 (EHC UI) — SPC-TM-011: patient search terms (name/MRN, diagnosis keyword) never travel in
 * a URL. The term rides a POST body on every search surface (registry, board, consultations
 * workspace, quick-jump, merge typeahead, registry count/exports); non-PII filters stay in the
 * query string for shareable views; legacy GET-with-term bookmarks redirect to the term-less URL
 * instead of failing; auth/authz gates are unchanged by the method switch.
 */
class TermOutOfUrlTest extends TestCase
{
    use RefreshDatabase;

    private function user(int $role, array $extra = []): User
    {
        return User::create(array_merge([
            'username' => 'tu_' . $role . '_' . substr(md5(uniqid('', true)), 0, 8),
            'name' => 'TU User', 'password' => 'secret12345', 'role' => $role, 'active' => 1,
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
        ], $extra));
    }

    private function admission(string $name, string $mrn, array $extra = []): Admission
    {
        $p = Patient::create(['mrn' => $mrn, 'name' => $name]);

        return Admission::create(array_merge([
            'patient_id' => $p->id, 'admit_date' => now()->subDays(3)->toDateString(), 'current_location' => 'Ward',
        ], $extra));
    }

    /* ---------------- Registry (admin) ---------------- */

    public function test_post_registry_search_returns_results_and_keeps_the_term_out_of_the_url(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $this->admission('Zebra Zeta', '70000001');
        $this->admission('Other Person', '70000002');

        $res = $this->actingAs($admin)->post('/registry', ['mode' => 'admissions', 'search' => 'Zebra']);

        $res->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Registry/Index')
                ->where('results.total', 1)
                ->where('results.data.0.mrn', '70000001')
                ->where('filters.search', 'Zebra'));

        // the page URL Inertia records (address bar / history entry) is term-less
        $url = (string) ($res->viewData('page')['url'] ?? '');
        $this->assertNotSame('', $url, 'the Inertia page object carries its URL');
        $this->assertStringNotContainsString('Zebra', $url);
        $this->assertStringNotContainsString('search=', $url);
    }

    public function test_legacy_get_registry_with_term_redirects_dropping_only_the_term(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);

        $this->actingAs($admin)
            ->get('/registry?mode=admissions&search=Zebra&from=2026-01-01')
            ->assertRedirect('/registry?mode=admissions&from=2026-01-01');

        // diagnosis keyword is the second PHI term field — same treatment
        $this->actingAs($admin)
            ->get('/registry?mode=diagnosis&keyword=pneumonia')
            ->assertRedirect('/registry?mode=diagnosis');
    }

    public function test_registry_post_still_enforces_guest_and_admin_gates(): void
    {
        $this->post('/registry', ['search' => 'Zebra'])->assertRedirect('/login');

        $this->actingAs($this->user(User::ROLE_CONSULTANT))
            ->post('/registry', ['search' => 'Zebra'])
            ->assertForbidden();
    }

    public function test_registry_count_and_exports_accept_the_term_via_post(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $this->admission('Zebra Zeta', '70000003');
        $this->admission('Other Person', '70000004');

        $this->actingAs($admin)
            ->postJson('/registry/count?mode=admissions', ['search' => 'Zebra'])
            ->assertOk()
            ->assertJson(['count' => 1]);

        $csv = $this->actingAs($admin)->post('/registry/export?mode=admissions', ['search' => 'Zebra']);
        $csv->assertOk();
        $body = $csv->streamedContent();
        $this->assertStringContainsString('70000003', $body, 'the POSTed term filters the export');
        $this->assertStringNotContainsString('70000004', $body);
    }

    /* ---------------- Patients board (all clinical roles) ---------------- */

    public function test_post_patients_board_search_works_and_get_with_term_redirects(): void
    {
        $consultant = $this->user(User::ROLE_CONSULTANT);
        $this->admission('Malak Board', '70000005', ['consultant_id' => $consultant->id]);

        $this->actingAs($consultant)
            ->post('/patients', ['search' => 'Malak'])
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Patients/Index')
                ->where('filters.search', 'Malak'));

        // legacy GET-with-term → redirect keeping the non-PII filter
        $this->actingAs($consultant)
            ->get('/patients?search=Malak&location=ICU')
            ->assertRedirect('/patients?location=ICU');
    }

    public function test_patients_board_post_still_requires_auth(): void
    {
        $this->post('/patients', ['search' => 'Malak'])->assertRedirect('/login');
    }

    /* ---------------- Consultations workspace ---------------- */

    public function test_consultations_term_posts_to_the_search_sibling_and_legacy_get_redirects(): void
    {
        // W1 scoping: the workspace is scoped to the viewer's specialty, so this fixture now states
        // the team explicitly. What the test pins — the search TERM riding in a POST body, never a
        // URL — is unchanged.
        $cardio = \App\Models\Specialty::create(['name' => 'Cardiology', 'is_subspecialty' => true, 'is_external' => false]);
        $consultant = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);
        Consultation::create(['mrn' => '70000006', 'patient_name' => 'Cons Findme', 'consultation_date' => now()->toDateString(),
            'indication' => [], 'current_location' => 'Ward', 'owning_specialty_id' => $cardio->id]);
        Consultation::create(['mrn' => '70000007', 'patient_name' => 'Cons Other', 'consultation_date' => now()->toDateString(),
            'indication' => [], 'current_location' => 'Ward', 'owning_specialty_id' => $cardio->id]);

        $this->actingAs($consultant)
            ->post('/consultations/search?status=active', ['search' => 'Findme'])
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Consultations/Index')
                ->where('consultations.total', 1)
                ->where('filters.search', 'Findme'));

        // legacy GET-with-term → redirect keeping status/scope
        $this->actingAs($consultant)
            ->get('/consultations?search=Findme&status=signed')
            ->assertRedirect('/consultations?status=signed');

        // a refresh/bookmark of the POST URI falls back to the term-less workspace, not a 405
        $this->actingAs($consultant)
            ->get('/consultations/search')
            ->assertRedirect(route('consultations.index'));
    }

    /* ---------------- Merge typeahead (admin) ---------------- */

    public function test_merge_typeahead_is_post_only_now(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        Patient::create(['mrn' => '70000008', 'name' => 'Searchable Sam']);

        $res = $this->actingAs($admin)->postJson('/api/patients/search', ['q' => 'Searchable'])->assertOk();
        $this->assertSame('70000008', $res->json('0.mrn'));

        $this->actingAs($admin)->get('/api/patients/search?q=Searchable')->assertStatus(405);
    }
}
