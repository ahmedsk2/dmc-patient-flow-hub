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
 * Wave 2 — Item 6: server-side sort for the Registry's admissions/diagnosis and consultations
 * tables. Only a whitelisted set of UI sort keys ever reaches the query (mapped to a hardcoded
 * column/expression — the request's `sort` value is NEVER interpolated into SQL); an off-whitelist
 * key is silently ignored and the legacy default order is kept. Sorting composes with the Wave-1
 * POST-body search term (SPC-TM-011) and the Inertia `sort` prop reports what was actually applied.
 */
class RegistrySortTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::create([
            'username' => 'rs_' . substr(md5(uniqid('', true)), 0, 8),
            'name' => 'RS Admin', 'password' => 'secret12345', 'role' => User::ROLE_ADMIN, 'active' => 1,
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
        ]);
    }

    private function admission(string $name, string $mrn, array $extra = []): Admission
    {
        $p = Patient::create(['mrn' => $mrn, 'name' => $name]);

        return Admission::create(array_merge([
            'patient_id' => $p->id, 'admit_date' => '2024-01-01', 'current_location' => 'Ward',
        ], $extra));
    }

    public function test_whitelisted_sort_by_name_reorders_admissions(): void
    {
        $this->admission('Zeta Patient', '80000001');
        $this->admission('Alpha Patient', '80000002');

        $this->actingAs($this->admin)
            ->get('/registry?mode=admissions&sort=name&dir=asc')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('results.data.0.mrn', '80000002')   // Alpha first, ascending
                ->where('results.data.1.mrn', '80000001')
                ->where('sort.sort', 'name')
                ->where('sort.dir', 'asc'));
    }

    public function test_whitelisted_sort_by_los_descending(): void
    {
        $this->admission('Short Stay', '80000003', ['admit_date' => '2024-01-01', 'discharge_date' => '2024-01-03']);   // 2 days
        $this->admission('Long Stay', '80000004', ['admit_date' => '2024-01-01', 'discharge_date' => '2024-01-11']);    // 10 days

        $this->actingAs($this->admin)
            ->get('/registry?mode=admissions&sort=los&dir=desc')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('results.data.0.mrn', '80000004')
                ->where('results.data.1.mrn', '80000003')
                ->where('sort.sort', 'los')
                ->where('sort.dir', 'desc'));
    }

    public function test_off_whitelist_sort_key_is_ignored_and_falls_back_to_the_default_order(): void
    {
        $this->admission('First Admitted', '80000005', ['admit_date' => '2024-01-01']);
        $this->admission('Second Admitted', '80000006', ['admit_date' => '2024-01-05']);

        // 'mrn' is NOT in the sortable whitelist — a raw column name from the request must never
        // reach the query; here it must simply be dropped, falling back to the legacy default.
        $this->actingAs($this->admin)
            ->get('/registry?mode=admissions&sort=mrn&dir=asc')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('results.data.0.mrn', '80000006')   // legacy default: admit_date DESC first
                ->where('sort.sort', null)
                ->where('sort.dir', null));
    }

    public function test_an_invalid_direction_is_also_ignored(): void
    {
        $this->admission('First Admitted', '80000012', ['admit_date' => '2024-01-01']);
        $this->admission('Second Admitted', '80000013', ['admit_date' => '2024-01-05']);

        $this->actingAs($this->admin)
            ->get('/registry?mode=admissions&sort=admit_date&dir=sideways')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('results.data.0.mrn', '80000013')   // legacy default order unchanged
                ->where('sort.sort', null)
                ->where('sort.dir', null));
    }

    public function test_sort_composes_with_the_post_body_search_term(): void
    {
        $this->admission('Findme Alpha', '80000007', ['admit_date' => '2024-02-01']);
        $this->admission('Findme Beta', '80000008', ['admit_date' => '2024-02-05']);
        $this->admission('Other Person', '80000009', ['admit_date' => '2024-02-10']);

        $this->actingAs($this->admin)
            ->post('/registry?sort=admit_date&dir=asc', ['mode' => 'admissions', 'search' => 'Findme'])
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('results.total', 2)
                ->where('results.data.0.mrn', '80000007')   // Findme Alpha, earliest admit_date first
                ->where('results.data.1.mrn', '80000008')
                ->where('filters.search', 'Findme')
                ->where('sort.sort', 'admit_date')
                ->where('sort.dir', 'asc'));
    }

    public function test_whitelisted_sort_on_consultations_mode(): void
    {
        Consultation::create(['mrn' => '80000010', 'patient_name' => 'Zed Consult', 'consultation_date' => '2024-03-01', 'indication' => [], 'current_location' => 'Ward']);
        Consultation::create(['mrn' => '80000011', 'patient_name' => 'Ann Consult', 'consultation_date' => '2024-03-02', 'indication' => [], 'current_location' => 'Ward']);

        $this->actingAs($this->admin)
            ->get('/registry?mode=consultations&sort=name&dir=asc')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('results.data.0.mrn', '80000011')
                ->where('sort.sort', 'name')
                ->where('sort.dir', 'asc'));
    }
}
