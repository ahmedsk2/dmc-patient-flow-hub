<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Wave 2, Item 2: global patient quick-jump endpoint (GET /api/patients/quick-search). PHI scope is
 * server-enforced: admins search active+discharged across all patients; non-admins (incl. observer)
 * get ACTIVE episodes only, D1-scoped (a consultant sees only their own group). < 2 chars → 422 + [].
 * The LIKE match is a prepared binding (no interpolation), so injection input runs harmlessly.
 */
class QuickSearchTest extends TestCase
{
    use RefreshDatabase;

    private function user(int $role, array $extra = []): User
    {
        return User::create(array_merge([
            'username' => 'qs_' . $role . '_' . substr(md5(uniqid('', true)), 0, 8),
            'name' => 'QS User', 'password' => 'secret12345', 'role' => $role, 'active' => 1,
        ], $extra));
    }

    private function patient(string $name, ?string $mrn = null): Patient
    {
        return Patient::create(['mrn' => $mrn ?? (string) random_int(10000000, 99999999), 'name' => $name]);
    }

    private function admission(Patient $p, array $extra = []): Admission
    {
        return Admission::create(array_merge([
            'patient_id' => $p->id, 'admit_date' => now()->subDays(3)->toDateString(), 'current_location' => 'Ward',
        ], $extra));
    }

    public function test_admin_searches_active_and_discharged_capped_at_8(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $cons = $this->user(User::ROLE_CONSULTANT);

        // 1 active assigned, 1 discharged, plus 8 more discharged "Ahmad" → 10 matches, cap should bite
        $this->admission($this->patient('Ahmad Active'), ['consultant_id' => $cons->id]);
        $this->admission($this->patient('Ahmad Discharged'), ['consultant_id' => $cons->id, 'discharge_date' => now()->subDay()->toDateString(), 'transfer_type' => 'discharge from ward']);
        for ($i = 0; $i < 8; $i++) {
            $this->admission($this->patient("Ahmad Extra {$i}"), ['discharge_date' => now()->subDay()->toDateString(), 'transfer_type' => 'discharge from ward']);
        }

        $res = $this->actingAs($admin)->getJson('/api/patients/quick-search?q=Ahmad')->assertOk();
        $this->assertCount(8, $res->json(), 'admin results are capped at 8');
        $statuses = collect($res->json())->pluck('status')->unique()->values()->all();
        $this->assertContains('discharged', $statuses, 'admin sees discharged episodes');
    }

    public function test_admin_discharged_result_routes_to_registry(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $this->admission($this->patient('Reema Closed', '40010001'), ['discharge_date' => now()->subDay()->toDateString(), 'transfer_type' => 'discharge from ward']);

        $res = $this->actingAs($admin)->getJson('/api/patients/quick-search?q=Reema')->assertOk();
        $row = collect($res->json())->firstWhere('status', 'discharged');
        $this->assertNotNull($row);
        $this->assertStringContainsString('/registry?mode=admissions&search=', $row['href']);
    }

    public function test_consultant_d1_scope_returns_only_their_own_active_patients(): void
    {
        $consultantA = $this->user(User::ROLE_CONSULTANT);
        $consultantB = $this->user(User::ROLE_CONSULTANT);

        $this->admission($this->patient('Mariam Mine', '40020001'), ['consultant_id' => $consultantA->id]);
        $this->admission($this->patient('Mariam Theirs', '40020002'), ['consultant_id' => $consultantB->id]);
        // a discharged one of A's must NOT appear (non-admins are active-only)
        $this->admission($this->patient('Mariam Gone', '40020003'), ['consultant_id' => $consultantA->id, 'discharge_date' => now()->subDay()->toDateString(), 'transfer_type' => 'discharge from ward']);

        $res = $this->actingAs($consultantA)->getJson('/api/patients/quick-search?q=Mariam')->assertOk();
        $mrns = collect($res->json())->pluck('mrn')->all();
        $this->assertSame(['40020001'], $mrns, 'consultant sees only their own ACTIVE patient');
    }

    public function test_observer_gets_only_active_rows_unscoped_like_a_registrar(): void
    {
        $observer = $this->user(User::ROLE_OBSERVER);
        $cons = $this->user(User::ROLE_CONSULTANT);

        $this->admission($this->patient('Omar Active', '40030001'), ['consultant_id' => $cons->id]);
        $this->admission($this->patient('Omar Closed', '40030002'), ['consultant_id' => $cons->id, 'discharge_date' => now()->subDay()->toDateString(), 'transfer_type' => 'discharge from ward']);

        $res = $this->actingAs($observer)->getJson('/api/patients/quick-search?q=Omar')->assertOk();
        $statuses = collect($res->json())->pluck('status')->all();
        $this->assertSame(['active'], $statuses, 'observer sees active rows only, no discharged');
    }

    public function test_short_query_returns_422_and_empty_array(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $this->actingAs($admin)->getJson('/api/patients/quick-search?q=a')
            ->assertStatus(422)
            ->assertExactJson([]);
    }

    public function test_sql_injection_input_runs_safely_via_prepared_binding(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $this->admission($this->patient('Untouched Patient', '40040001'), ['consultant_id' => $admin->id]);

        // a classic injection string — the prepared LIKE binding treats it as a literal, no error,
        // and the patients table is intact afterwards
        $this->actingAs($admin)->getJson("/api/patients/quick-search?q=" . urlencode("'; DROP TABLE patients; --"))
            ->assertOk()
            ->assertExactJson([]);   // no patient name/mrn contains that literal
        $this->assertSame(1, Patient::count(), 'patients table survived the injection input');
    }

    public function test_guest_is_blocked_from_the_endpoint(): void
    {
        // unauthenticated → the auth middleware blocks it. The app treats /api/* as JSON, so the
        // guard returns 401 (never a 200 with patient data) for both HTML and JSON requests.
        $this->get('/api/patients/quick-search?q=Ahmad')->assertStatus(401);
        $this->getJson('/api/patients/quick-search?q=Ahmad')->assertStatus(401);
    }
}
