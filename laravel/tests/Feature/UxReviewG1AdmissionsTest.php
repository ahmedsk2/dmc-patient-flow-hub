<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\Country;
use App\Models\Patient;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Role/UX review 2026-09-24, group g1-admissions — Problem #1 (readmitting a known MRN silently
 * overwrote the patient's name/age/gender/nationality for every visit, with no lookup or warning):
 *
 *  (a) AdmissionsController::lookupMrn() — POST /admissions/lookup-mrn — tells the admit form
 *      whether a typed MRN already belongs to a known patient (and whether they have an active
 *      episode), authorized and throttled exactly like the admit form itself.
 *  (b) StoreAdmissionRequest::withValidator() — when the MRN belongs to a known patient AND the
 *      submitted name/age/gender/nationality differ from the stored record, the write is refused
 *      (422, `confirm_identity_update`) unless that field is explicitly sent — "latest details win"
 *      stays possible, it can just never happen silently any more.
 */
class UxReviewG1AdmissionsTest extends TestCase
{
    use RefreshDatabase;

    private function user(int $role = User::ROLE_CONSULTANT, array $extra = []): User
    {
        return User::create(array_merge([
            'username' => 'ux1_'.substr(md5(uniqid('', true)), 0, 10),
            'name' => 'UX1 User', 'password' => 'secret12345', 'role' => $role, 'active' => 1,
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
        ], $extra));
    }

    private function admin(): User
    {
        return $this->user(User::ROLE_ADMIN);
    }

    /** A registrar carrying the Can-Add capability — the normal admit-form persona. */
    private function adder(): User
    {
        return $this->user(User::ROLE_REGISTRAR, ['can_add' => true]);
    }

    /** Full legacy-required admission payload (mirrors AdmissionDoubleSubmitTest::admitPayload). */
    private function admitPayload(array $overrides = []): array
    {
        Country::firstOrCreate(['name' => 'Saudi Arabia'], ['code' => 'SA']);
        DB::table('icd10')->updateOrInsert(['code' => 'J18.9'], ['name' => 'Pneumonia']);

        return array_merge([
            'mrn' => (string) random_int(10000000, 99999999), 'name' => 'UX Review Patient', 'age' => 47,
            'gender' => 'Male', 'nationality' => 'Saudi Arabia', 'bed' => 'W-12',
            'admit_date' => now()->toDateString(), 'admitted_from' => 'ER',
            'current_location' => 'Ward', 'diagnoses' => ['J18.9'],
        ], $overrides);
    }

    // ---- (a) lookupMrn: authorization + throttle -------------------------------------------------

    public function test_lookup_mrn_denies_observers(): void
    {
        $observer = $this->user(User::ROLE_OBSERVER);

        $this->actingAs($observer)->postJson('/admissions/lookup-mrn', ['mrn' => '11112222'])
            ->assertStatus(403);
    }

    public function test_lookup_mrn_denies_a_clinical_role_without_can_add(): void
    {
        $resident = $this->user(User::ROLE_RESIDENT, ['can_add' => false]);

        $this->actingAs($resident)->postJson('/admissions/lookup-mrn', ['mrn' => '11112222'])
            ->assertStatus(403);
    }

    public function test_lookup_mrn_allows_a_can_add_user(): void
    {
        $this->actingAs($this->adder())->postJson('/admissions/lookup-mrn', ['mrn' => '11112222'])
            ->assertOk()
            ->assertJson(['found' => false]);
    }

    public function test_lookup_mrn_route_carries_the_phi_throttle_middleware(): void
    {
        $route = Route::getRoutes()->getByName('admissions.lookupMrn');
        $this->assertNotNull($route, 'the lookup-mrn route must be named admissions.lookupMrn');
        $this->assertContains('throttle:phi', $route->middleware(),
            'a new PHI-returning route must carry throttle:phi (CLAUDE.md convention)');
    }

    public function test_lookup_mrn_shares_the_phi_throttle_bucket_with_other_phi_routes(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);

        // 239 cheap calls against the same per-user 'phi' bucket (PhiThrottleTest's own pattern),
        // then ONE real call to lookup-mrn to prove it drains — and is throttled by — that bucket.
        for ($i = 0; $i < 239; $i++) {
            $this->getJson('/api/icd10?q=x');
        }
        $this->assertNotSame(429, $this->postJson('/admissions/lookup-mrn', ['mrn' => '11112222'])->getStatusCode(),
            'the 240th PHI request in the minute is still within the allowance');
        $this->assertSame(429, $this->postJson('/admissions/lookup-mrn', ['mrn' => '11112222'])->getStatusCode(),
            'the 241st PHI request in the minute — here lookup-mrn — must be throttled');
    }

    // ---- (a) lookupMrn: response shape -------------------------------------------------------------

    public function test_lookup_mrn_reports_not_found_for_an_unknown_mrn(): void
    {
        $this->actingAs($this->adder())
            ->postJson('/admissions/lookup-mrn', ['mrn' => '30000001'])
            ->assertOk()
            ->assertExactJson(['found' => false]);
    }

    public function test_lookup_mrn_returns_the_stored_demographics_with_no_active_episode(): void
    {
        Patient::create(['mrn' => '30000002', 'name' => 'Known Patient', 'age' => 61, 'gender' => 'Female', 'nationality' => 'Egypt']);
        // a DISCHARGED prior episode — must not count as active
        Admission::create(['patient_id' => Patient::where('mrn', '30000002')->value('id'),
            'admit_date' => now()->subDays(10)->toDateString(), 'discharge_date' => now()->subDays(5)->toDateString(),
            'current_location' => 'Ward', 'is_longterm' => 0, 'is_new_assignment' => 0]);

        $this->actingAs($this->adder())
            ->postJson('/admissions/lookup-mrn', ['mrn' => '30000002'])
            ->assertOk()
            ->assertJson([
                'found' => true,
                'has_active_episode' => false,
                'patient' => ['name' => 'Known Patient', 'age' => 61, 'gender' => 'Female', 'nationality' => 'Egypt'],
            ]);
    }

    public function test_lookup_mrn_flags_an_existing_active_episode(): void
    {
        $p = Patient::create(['mrn' => '30000003', 'name' => 'Still Admitted', 'age' => 33]);
        Admission::create(['patient_id' => $p->id, 'admit_date' => now()->toDateString(),
            'current_location' => 'Ward', 'is_longterm' => 0, 'is_new_assignment' => 0]);

        $this->actingAs($this->adder())
            ->postJson('/admissions/lookup-mrn', ['mrn' => '30000003'])
            ->assertOk()
            ->assertJson(['found' => true, 'has_active_episode' => true]);
    }

    // ---- (b) StoreAdmissionRequest: silent-overwrite guard -----------------------------------------

    public function test_a_brand_new_mrn_needs_no_confirmation(): void
    {
        $payload = $this->admitPayload(['mrn' => '40000001']);

        $this->actingAs($this->adder())->post('/admissions', $payload)
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(1, Admission::count());
        $this->assertSame('UX Review Patient', Patient::where('mrn', '40000001')->value('name'));
    }

    public function test_readmitting_with_identical_demographics_needs_no_confirmation(): void
    {
        Patient::create(['mrn' => '40000002', 'name' => 'Same Details', 'age' => 47, 'gender' => 'Male', 'nationality' => 'Saudi Arabia']);
        $payload = $this->admitPayload([
            'mrn' => '40000002', 'name' => 'Same Details', 'age' => 47, 'gender' => 'Male', 'nationality' => 'Saudi Arabia',
        ]);

        $this->actingAs($this->adder())->post('/admissions', $payload)
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(1, Admission::count());
    }

    public function test_readmitting_with_changed_demographics_is_refused_without_confirmation(): void
    {
        Patient::create(['mrn' => '40000003', 'name' => 'Original Name', 'age' => 50, 'gender' => 'Male', 'nationality' => 'Saudi Arabia']);
        // a mistyped/changed name, unconfirmed
        $payload = $this->admitPayload(['mrn' => '40000003', 'name' => 'Different Name']);

        $response = $this->actingAs($this->adder())->post('/admissions', $payload);
        $response->assertSessionHasErrors('confirm_identity_update');

        $this->assertSame(0, Admission::count(), 'no admission may be created while the identity change is unconfirmed');
        $this->assertSame('Original Name', Patient::where('mrn', '40000003')->value('name'),
            'the canonical record must not be touched while the identity change is unconfirmed');
    }

    public function test_the_confirmation_message_names_every_field_that_would_change(): void
    {
        Patient::create(['mrn' => '40000004', 'name' => 'Original Name', 'age' => 50, 'gender' => 'Male', 'nationality' => 'Saudi Arabia']);
        $payload = $this->admitPayload(['mrn' => '40000004', 'name' => 'Different Name', 'age' => 51]);

        $response = $this->actingAs($this->adder())->post('/admissions', $payload);
        $response->assertSessionHasErrors('confirm_identity_update');
        $message = session('errors')->first('confirm_identity_update');
        $this->assertStringContainsString('name', $message);
        $this->assertStringContainsString('age', $message);
        $this->assertStringNotContainsString('gender', $message, 'gender was not changed and must not be listed');
    }

    public function test_readmitting_with_changed_demographics_and_explicit_confirmation_updates_the_record(): void
    {
        Patient::create(['mrn' => '40000005', 'name' => 'Original Name', 'age' => 50, 'gender' => 'Male', 'nationality' => 'Saudi Arabia']);
        $payload = $this->admitPayload([
            'mrn' => '40000005', 'name' => 'Corrected Name', 'confirm_identity_update' => true,
        ]);

        $this->actingAs($this->adder())->post('/admissions', $payload)
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(1, Admission::count());
        $this->assertSame('Corrected Name', Patient::where('mrn', '40000005')->value('name'),
            'an explicitly confirmed change must still update the canonical record ("latest details win")');
    }

    public function test_a_second_active_episode_is_refused_before_the_identity_question_is_even_asked(): void
    {
        $p = Patient::create(['mrn' => '40000006', 'name' => 'Original Name', 'age' => 50, 'gender' => 'Male', 'nationality' => 'Saudi Arabia']);
        Admission::create(['patient_id' => $p->id, 'admit_date' => now()->toDateString(),
            'current_location' => 'Ward', 'is_longterm' => 0, 'is_new_assignment' => 0]);

        // changed name AND still-active MRN — the pre-existing duplicate-active-episode guard must
        // win outright, not stack a confusing second "confirm identity" error alongside it
        $payload = $this->admitPayload(['mrn' => '40000006', 'name' => 'Different Name']);

        $response = $this->actingAs($this->adder())->post('/admissions', $payload);
        $response->assertSessionHasErrors(['mrn' => 'This MRN already has an active admission.']);
        $this->assertFalse(session('errors')->has('confirm_identity_update'));

        $this->assertSame(1, Admission::count());
    }
}
