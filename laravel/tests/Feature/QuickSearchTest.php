<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Wave 2, Item 2 (+ Wave 1 EHC UI / SPC-TM-011): global patient quick-jump endpoint —
 * POST /api/patients/quick-search, term (or the palette's recents id list) in the BODY, never a
 * query string. PHI scope is server-enforced: admins search active+discharged across all patients;
 * non-admins (incl. observer) get ACTIVE episodes only, D1-scoped (a consultant sees only their own
 * group). < 2 chars → 422 + []. The LIKE match is a prepared binding (no interpolation). Rows carry
 * `dest` (board|registry) instead of a URL so no MRN-bearing link is ever minted.
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

        $res = $this->actingAs($admin)->postJson('/api/patients/quick-search', ['q' => 'Ahmad'])->assertOk();
        $this->assertCount(8, $res->json(), 'admin results are capped at 8');
        $statuses = collect($res->json())->pluck('status')->unique()->values()->all();
        $this->assertContains('discharged', $statuses, 'admin sees discharged episodes');
    }

    public function test_rows_carry_dest_and_identity_ribbon_fields_but_no_url(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $cons = $this->user(User::ROLE_CONSULTANT);
        $p = $this->patient('Reema Closed', '40010001');
        $p->update(['age' => 61, 'gender' => 'Female']);
        $this->admission($p, ['consultant_id' => $cons->id, 'discharge_date' => now()->subDay()->toDateString(), 'transfer_type' => 'discharge from ward', 'outcome' => 'Alive']);
        $this->admission($this->patient('Reema Open', '40010002'), ['consultant_id' => $cons->id]);

        $res = $this->actingAs($admin)->postJson('/api/patients/quick-search', ['q' => 'Reema'])->assertOk();

        $closed = collect($res->json())->firstWhere('status', 'discharged');
        $open = collect($res->json())->firstWhere('status', 'active');
        $this->assertSame('registry', $closed['dest'], 'discharged rows route to the registry');
        $this->assertSame('board', $open['dest'], 'live rows route to the board');
        // identity-ribbon fields (Wave 1)
        $this->assertSame(61, $closed['age']);
        $this->assertSame('Female', $closed['gender']);
        $this->assertSame('Ward', $closed['location']);
        $this->assertFalse($closed['deceased']);
        $this->assertNotNull($closed['admit_date']);
        // SPC-TM-011: no URL (and so no MRN-bearing query string) in the payload
        $this->assertArrayNotHasKey('href', $closed);
        $this->assertStringNotContainsString('search=', json_encode($res->json()));
    }

    public function test_deceased_flag_is_set_from_the_outcome(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $this->admission($this->patient('Dara Deceased', '40010003'), ['discharge_date' => now()->subDay()->toDateString(), 'transfer_type' => 'discharge from ward', 'outcome' => 'Dead']);

        $res = $this->actingAs($admin)->postJson('/api/patients/quick-search', ['q' => 'Dara'])->assertOk();
        $this->assertTrue($res->json('0.deceased'));
    }

    public function test_consultant_d1_scope_returns_only_their_own_active_patients(): void
    {
        $consultantA = $this->user(User::ROLE_CONSULTANT);
        $consultantB = $this->user(User::ROLE_CONSULTANT);

        $this->admission($this->patient('Mariam Mine', '40020001'), ['consultant_id' => $consultantA->id]);
        $this->admission($this->patient('Mariam Theirs', '40020002'), ['consultant_id' => $consultantB->id]);
        // a discharged one of A's must NOT appear (non-admins are active-only)
        $this->admission($this->patient('Mariam Gone', '40020003'), ['consultant_id' => $consultantA->id, 'discharge_date' => now()->subDay()->toDateString(), 'transfer_type' => 'discharge from ward']);

        $res = $this->actingAs($consultantA)->postJson('/api/patients/quick-search', ['q' => 'Mariam'])->assertOk();
        $mrns = collect($res->json())->pluck('mrn')->all();
        $this->assertSame(['40020001'], $mrns, 'consultant sees only their own ACTIVE patient');
    }

    public function test_observer_gets_only_active_rows_unscoped_like_a_registrar(): void
    {
        $observer = $this->user(User::ROLE_OBSERVER);
        $cons = $this->user(User::ROLE_CONSULTANT);

        $this->admission($this->patient('Omar Active', '40030001'), ['consultant_id' => $cons->id]);
        $this->admission($this->patient('Omar Closed', '40030002'), ['consultant_id' => $cons->id, 'discharge_date' => now()->subDay()->toDateString(), 'transfer_type' => 'discharge from ward']);

        $res = $this->actingAs($observer)->postJson('/api/patients/quick-search', ['q' => 'Omar'])->assertOk();
        $statuses = collect($res->json())->pluck('status')->all();
        $this->assertSame(['active'], $statuses, 'observer sees active rows only, no discharged');
    }

    public function test_short_query_returns_422_and_empty_array(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $this->actingAs($admin)->postJson('/api/patients/quick-search', ['q' => 'a'])
            ->assertStatus(422)
            ->assertExactJson([]);
    }

    public function test_sql_injection_input_runs_safely_via_prepared_binding(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $this->admission($this->patient('Untouched Patient', '40040001'), ['consultant_id' => $admin->id]);

        // a classic injection string — the prepared LIKE binding treats it as a literal, no error,
        // and the patients table is intact afterwards
        $this->actingAs($admin)->postJson('/api/patients/quick-search', ['q' => "'; DROP TABLE patients; --"])
            ->assertOk()
            ->assertExactJson([]);   // no patient name/mrn contains that literal
        $this->assertSame(1, Patient::count(), 'patients table survived the injection input');
    }

    public function test_guest_is_blocked_from_the_endpoint(): void
    {
        // unauthenticated → the auth middleware blocks it. The app treats /api/* as JSON, so the
        // guard returns 401 (never a 200 with patient data).
        $this->postJson('/api/patients/quick-search', ['q' => 'Ahmad'])->assertStatus(401);
    }

    public function test_the_get_channel_is_gone(): void
    {
        // SPC-TM-011: the term must never be accepted via a query string — GET is 405 now.
        $admin = $this->user(User::ROLE_ADMIN);
        $this->actingAs($admin)->get('/api/patients/quick-search?q=Ahmad')->assertStatus(405);
    }

    /* ---- Wave 1: recents re-hydration by opaque admission id (same endpoint, same scope) ------ */

    public function test_ids_lookup_returns_rows_for_admins_including_discharged(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $a1 = $this->admission($this->patient('Recent One', '40050001'));
        $a2 = $this->admission($this->patient('Recent Two', '40050002'), ['discharge_date' => now()->subDay()->toDateString(), 'transfer_type' => 'discharge from ward']);

        $res = $this->actingAs($admin)->postJson('/api/patients/quick-search', ['ids' => [$a1->id, $a2->id]])->assertOk();
        $this->assertEqualsCanonicalizing([$a1->id, $a2->id], collect($res->json())->pluck('id')->all());
    }

    public function test_ids_lookup_enforces_the_same_scope_as_the_search(): void
    {
        $consultantA = $this->user(User::ROLE_CONSULTANT);
        $consultantB = $this->user(User::ROLE_CONSULTANT);

        $mine = $this->admission($this->patient('Scope Mine', '40060001'), ['consultant_id' => $consultantA->id]);
        $theirs = $this->admission($this->patient('Scope Theirs', '40060002'), ['consultant_id' => $consultantB->id]);
        $gone = $this->admission($this->patient('Scope Gone', '40060003'), ['consultant_id' => $consultantA->id, 'discharge_date' => now()->subDay()->toDateString(), 'transfer_type' => 'discharge from ward']);

        // a consultant re-hydrating stale recents gets ONLY their own active episode back
        $res = $this->actingAs($consultantA)
            ->postJson('/api/patients/quick-search', ['ids' => [$mine->id, $theirs->id, $gone->id]])
            ->assertOk();
        $this->assertSame([$mine->id], collect($res->json())->pluck('id')->all());
    }

    public function test_ids_lookup_is_capped_and_ignores_garbage(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        $keep = [];
        for ($i = 0; $i < 12; $i++) {
            $keep[] = $this->admission($this->patient("Cap Patient {$i}"))->id;
        }

        $res = $this->actingAs($admin)
            ->postJson('/api/patients/quick-search', ['ids' => array_merge(['abc', null], $keep)])
            ->assertOk();
        $this->assertLessThanOrEqual(10, count($res->json()), 'ids lookup is capped at 10');
    }
}
