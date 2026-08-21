<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\AuditLog;
use App\Models\Consultation;
use App\Models\ConsultationReason;
use App\Models\Patient;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Residual batch R3 — final polish:
 *  1. consultation form legacy parity: age/bed/from/to-service/indication/consultant all REQUIRED
 *  2. statistics destination donut uses the shared legacy 7-bucket split (non-ICU) and the
 *     day-interval bucket cap surfaces a truncated flag
 *  3. printable active list (/active-list): authed all roles, D1-scoped like the board
 *  4. username edit: profile self-service (audited with the old value) + admin Control edit,
 *     both with uniqueness ignoring self
 */
class ResidualR3Test extends TestCase
{
    use RefreshDatabase;

    private function user(int $role, array $extra = []): User
    {
        return User::create(array_merge([
            'username' => 'r3_' . substr(md5(uniqid('', true)), 0, 10),
            'name' => 'R3 User', 'password' => 'secret12345', 'role' => $role, 'active' => 1,
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
        ], $extra));
    }

    private function admin(): User
    {
        return $this->user(User::ROLE_ADMIN);
    }

    private function admission(array $overrides = [], ?Patient $patient = null): Admission
    {
        $p = $patient ?? Patient::create(['mrn' => (string) random_int(10000000, 99999999), 'name' => 'R3 Patient']);

        return Admission::create(array_merge([
            'patient_id' => $p->id, 'admit_date' => now()->subDays(3)->toDateString(),
            'current_location' => 'Ward', 'is_longterm' => 0, 'is_new_assignment' => 0,
        ], $overrides));
    }

    // ---- 1. consultation required fields (legacy parity) ---------------------------------------

    private function consultationPayload(array $overrides = []): array
    {
        $consultant = $this->user(User::ROLE_CONSULTANT, ['full_name' => 'Dr Receiver']);
        $reason = ConsultationReason::create(['name' => 'R3 Reason']);
        // H1: a consultant is required only for an INTERNAL to_service — make Cardiology one
        \App\Models\Specialty::firstOrCreate(['name' => 'Cardiology'], ['is_subspecialty' => true, 'is_external' => false]);

        return array_merge([
            'mrn' => '30001234', 'patient_name' => 'Consult Pt', 'age' => 41, 'bed' => 'B-07',
            'current_location' => 'Ward', 'consultation_date' => now()->toDateString(),
            'consultation_from' => 'ER', 'to_service' => 'Cardiology',
            'consultant_id' => $consultant->id, 'indication' => [$reason->id],
        ], $overrides);
    }

    public function test_consultation_store_rejects_missing_indication_and_consultant(): void
    {
        $payload = $this->consultationPayload();
        unset($payload['indication'], $payload['consultant_id']);

        $this->actingAs($this->user(User::ROLE_REGISTRAR))->from('/consultations')
            ->post('/consultations', $payload)
            ->assertRedirect('/consultations')
            ->assertSessionHasErrors(['indication', 'consultant_id']);
        $this->assertSame(0, Consultation::count());

        // an EMPTY indication array fails min:1 too
        $this->actingAs($this->user(User::ROLE_REGISTRAR))->from('/consultations')
            ->post('/consultations', $this->consultationPayload(['indication' => []]))
            ->assertSessionHasErrors('indication');
        $this->assertSame(0, Consultation::count());
    }

    public function test_consultation_store_requires_bed_from_and_to_service(): void
    {
        // O1-2: age is now NULLABLE (legacy v_int_range skipped empties; keeps imported null-age
        // consultations re-savable). bed / from / to-service remain required.
        $payload = $this->consultationPayload();
        unset($payload['age'], $payload['bed'], $payload['consultation_from'], $payload['to_service']);

        $this->actingAs($this->user(User::ROLE_REGISTRAR))->from('/consultations')
            ->post('/consultations', $payload)
            ->assertSessionHasErrors(['bed', 'consultation_from', 'to_service'])
            ->assertSessionDoesntHaveErrors('age');
        $this->assertSame(0, Consultation::count());
    }

    public function test_consultation_store_accepts_a_full_legacy_payload(): void
    {
        // W1: booking into a specialty you do not belong to is the COORDINATOR capability. A unit
        // registrar who books consults for every team is precisely who gets the flag.
        $this->actingAs($this->user(User::ROLE_REGISTRAR, ['can_coordinate_consultations' => true]))
            ->post('/consultations', $this->consultationPayload())
            ->assertRedirect()->assertSessionHasNoErrors();

        $c = Consultation::first();
        $this->assertNotNull($c);
        $this->assertSame('30001234', (string) $c->mrn);
        $this->assertNotNull($c->consultant_id);
        $this->assertCount(1, $c->indication);
    }

    public function test_consultations_page_ships_specialty_objects_and_on_service_options(): void
    {
        \App\Models\Specialty::create(['name' => 'Cardiology', 'is_subspecialty' => true, 'is_external' => false]);
        $this->user(User::ROLE_CONSULTANT, ['full_name' => 'Dr On', 'on_service' => 1]);

        $this->actingAs($this->admin())->get('/consultations')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('specialties.0.name', 'Cardiology')
                ->where('specialties.0.is_external', false)
                ->has('specialties.0.id')
                ->where('consultants.0.on_service', true));
    }

    // ---- 2. statistics: bucketed destinations + day-interval truncation ------------------------

    public function test_statistics_destinations_use_the_legacy_seven_buckets_non_icu(): void
    {
        // ward discharge to Home; ward discharge to an UNKNOWN value -> 'To Other Speciality';
        // an ICU episode to Home must be EXCLUDED (non-ICU rule)
        $this->admission(['admit_date' => '2024-06-01', 'discharge_date' => '2024-06-05',
            'transfer_type' => 'discharge from ward', 'outcome' => 'Alive', 'discharge_to' => 'Home']);
        $this->admission(['admit_date' => '2024-06-02', 'discharge_date' => '2024-06-06',
            'transfer_type' => 'discharge from ward', 'outcome' => 'Alive', 'discharge_to' => 'Rehab Unit']);
        $this->admission(['admit_date' => '2024-06-03', 'discharge_date' => '2024-06-07',
            'current_location' => 'ICU', 'transfer_type' => 'discharge from ICU', 'outcome' => 'Alive',
            'discharge_to' => 'Home']);

        $this->actingAs($this->admin())->get('/statistics?from=2024-06-01&to=2024-06-30')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('destinations.labels', [
                    'Intensive Care (ICU)', 'Home', 'Mortuary', 'Other Facility', 'Absconded', 'LAMA', 'To Other Speciality',
                ])
                ->where('destinations.data', [0, 1, 0, 0, 0, 0, 1]));
    }

    public function test_statistics_day_interval_truncates_at_the_bucket_cap(): void
    {
        // 2 years of days blows the ~370-bucket cap -> truncated=true
        $this->actingAs($this->admin())->get('/statistics?from=2023-01-01&to=2024-12-31&interval=day')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('truncated', true)
                ->where('interval', 'day'));

        // a month-interval request over the same range is NOT truncated
        $this->actingAs($this->admin())->get('/statistics?from=2023-01-01&to=2024-12-31&interval=month')
            ->assertInertia(fn (AssertableInertia $p) => $p->where('truncated', false));

        // a short day-interval range is NOT truncated
        $this->actingAs($this->admin())->get('/statistics?from=2024-06-01&to=2024-06-30&interval=day')
            ->assertInertia(fn (AssertableInertia $p) => $p->where('truncated', false));
    }

    // ---- 3. printable active list ----------------------------------------------------------------

    public function test_active_list_renders_group_data_for_all_roles(): void
    {
        $c = $this->user(User::ROLE_CONSULTANT, ['full_name' => 'Dr Census']);
        \App\Models\Icd10::create(['code' => 'J18.9', 'name' => 'Pneumonia, unspecified organism']);
        $a = $this->admission(['consultant_id' => $c->id, 'bed' => 'W-12']);
        $a->diagnoses()->create(['seq' => 1, 'icd10_code' => 'J18.9']);

        $this->actingAs($this->admin())->get('/active-list')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->component('ActiveList')
                ->count('groups', 1)
                ->where('groups.0.name', 'Dr Census')
                ->where('groups.0.patients.0.bed', 'W-12')
                ->where('groups.0.patients.0.diagnoses.0.name', 'Pneumonia, unspecified organism'));

        // observers may view the read-only census too
        $this->actingAs($this->user(User::ROLE_OBSERVER))->get('/active-list')->assertOk();
    }

    public function test_active_list_shows_every_consultant_to_consultants(): void
    {
        // H2/B11 deliberately REMOVED the D1 scoping here: the legacy active-list.php was the
        // printed whole-ward census, visible in full to every logged-in user.
        $own = $this->user(User::ROLE_CONSULTANT, ['full_name' => 'Dr Own']);
        $other = $this->user(User::ROLE_CONSULTANT, ['full_name' => 'Dr Other']);
        $this->admission(['consultant_id' => $own->id]);
        $this->admission(['consultant_id' => $other->id]);

        $this->actingAs($own)->get('/active-list')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->count('groups', 2)
                ->where('groups', fn ($g) => collect($g)->pluck('name')->sort()->values()->all() === ['Dr Other', 'Dr Own']));
    }

    // ---- 4. username edit ---------------------------------------------------------------------------

    public function test_profile_username_change_persists_and_is_audited_with_old_value(): void
    {
        $u = $this->user(User::ROLE_RESIDENT, ['full_name' => 'Dr Rename', 'username' => 'old_login']);

        $this->actingAs($u)->put('/profile', [
            'full_name' => 'Dr Rename', 'email' => 'rename@example.test', 'username' => 'new_login',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('new_login', $u->fresh()->username);
        $audit = AuditLog::where('action', 'user.username_change')->where('entity_id', (string) $u->id)->first();
        $this->assertNotNull($audit, 'username change must be audited');
        $this->assertSame('old_login', $audit->details['was'] ?? null);
        $this->assertSame('new_login', $audit->details['username'] ?? null);
    }

    public function test_profile_username_must_be_unique_ignoring_self(): void
    {
        $this->user(User::ROLE_RESIDENT, ['username' => 'taken_login']);
        $u = $this->user(User::ROLE_RESIDENT, ['username' => 'mine_login']);

        $this->actingAs($u)->from('/profile')->put('/profile', [
            'full_name' => 'Dr Mine', 'email' => null, 'username' => 'taken_login',
        ])->assertRedirect('/profile')->assertSessionHasErrors('username');
        $this->assertSame('mine_login', $u->fresh()->username);

        // keeping one's OWN username is not a collision — and an unchanged name is NOT audited
        $this->actingAs($u)->put('/profile', [
            'full_name' => 'Dr Mine', 'email' => null, 'username' => 'mine_login',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(0, AuditLog::where('action', 'user.username_change')->count());
    }

    public function test_profile_username_rejects_invalid_characters(): void
    {
        $u = $this->user(User::ROLE_RESIDENT, ['username' => 'valid_login']);

        $this->actingAs($u)->from('/profile')->put('/profile', [
            'full_name' => 'Dr Valid', 'email' => null, 'username' => 'has spaces!',
        ])->assertRedirect('/profile')->assertSessionHasErrors('username');
        $this->assertSame('valid_login', $u->fresh()->username);
    }

    public function test_control_user_edit_updates_username_with_uniqueness(): void
    {
        $this->user(User::ROLE_RESIDENT, ['username' => 'ctl_taken']);
        $u = $this->user(User::ROLE_RESIDENT, ['username' => 'ctl_before']);
        $payload = [
            'username' => 'ctl_after', 'role' => (int) $u->role, 'active' => true, 'on_service' => false,
            'specialty_id' => null, 'can_assign' => false, 'can_add' => false, 'can_manage' => false, 'can_modify' => false,
        ];

        $this->actingAs($this->admin())->put("/control/users/{$u->id}", $payload)
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('ctl_after', $u->fresh()->username);

        $this->actingAs($this->admin())->from('/control')
            ->put("/control/users/{$u->id}", array_merge($payload, ['username' => 'ctl_taken']))
            ->assertRedirect('/control')->assertSessionHasErrors('username');
        $this->assertSame('ctl_after', $u->fresh()->username);
    }
}
