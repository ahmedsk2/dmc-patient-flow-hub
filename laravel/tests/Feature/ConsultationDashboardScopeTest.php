<?php

namespace Tests\Feature;

use App\Models\Consultation;
use App\Models\Specialty;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * W4 — Task 25. GET /consultations/dashboard is a PHYSICIAN route (ordinary auth group), not an
 * admin one: Statistics / Registry / Reports stay admin-only and are untouched. Scoping is
 * Consultation::scopeVisibleTo(); admins + coordinators may narrow to one specialty with
 * ?specialty_id=N. Observers are refused BEFORE any capability flag is consulted.
 *
 * Metrics asserted here:
 *   openCounts — COUNT(*) GROUP BY status over rows with status <> 'signed_off'
 *   ageing     — DATEDIFF(CURDATE(), DATE(COALESCE(requested_at, consultation_date))) bucketed
 *                0-2d / 3-7d / >7d, with rows lacking BOTH dates reported as 'unknown'
 */
class ConsultationDashboardScopeTest extends TestCase
{
    use RefreshDatabase;

    private function user(int $role = User::ROLE_CONSULTANT, array $extra = []): User
    {
        return User::create(array_merge([
            'username' => 'cds_' . substr(md5(uniqid('', true)), 0, 10),
            'name' => 'CDS User', 'password' => 'secret12345', 'role' => $role, 'active' => 1,
            'email_verified_at' => now(),
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
        ], $extra));
    }

    private function consult(array $attrs = []): Consultation
    {
        return Consultation::create(array_merge([
            'mrn' => (string) random_int(10000000, 99999999),
            'patient_name' => 'CDS Patient',
            'consultation_date' => now()->toDateString(),
            'indication' => [],
            'status' => Consultation::STATUS_NEW,
        ], $attrs));
    }

    public function test_observer_is_refused_before_any_capability_flag(): void
    {
        // can_manage AND the coordinator flag are both ON — the observer gate still wins.
        $observer = $this->user(User::ROLE_OBSERVER, [
            'can_manage' => 1, 'can_coordinate_consultations' => 1,
        ]);

        $this->actingAs($observer)->get('/consultations/dashboard')->assertForbidden();
    }

    public function test_consultant_sees_only_their_own_specialty_counts(): void
    {
        $cardio = Specialty::create(['name' => 'Cardiology']);
        $nephro = Specialty::create(['name' => 'Nephrology']);
        $doc = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);

        $this->consult(['owning_specialty_id' => $cardio->id, 'status' => Consultation::STATUS_NEW]);
        $this->consult(['owning_specialty_id' => $cardio->id, 'status' => Consultation::STATUS_ACTIVE]);
        $this->consult(['owning_specialty_id' => $cardio->id, 'status' => Consultation::STATUS_ACTIVE]);
        $this->consult(['owning_specialty_id' => $cardio->id, 'status' => Consultation::STATUS_SIGNED_OFF]);
        $this->consult(['owning_specialty_id' => $nephro->id, 'status' => Consultation::STATUS_ONGOING]);

        $this->actingAs($doc)->get('/consultations/dashboard')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->component('Consultations/Dashboard')
                ->where('openCounts.new', 1)
                ->where('openCounts.active', 2)
                ->where('openCounts.ongoing', 0)          // the ongoing row belongs to Nephrology
                ->where('openCounts.total', 3)            // signed_off is never an open count
                ->where('canPick', false)
                ->where('scopeLabel', 'Cardiology'));
    }

    public function test_admin_sees_all_specialties_and_can_narrow_to_one(): void
    {
        $cardio = Specialty::create(['name' => 'Cardiology']);
        $nephro = Specialty::create(['name' => 'Nephrology']);
        $admin = $this->user(User::ROLE_ADMIN);

        $this->consult(['owning_specialty_id' => $cardio->id, 'status' => Consultation::STATUS_NEW]);
        $this->consult(['owning_specialty_id' => $nephro->id, 'status' => Consultation::STATUS_ONGOING]);

        $this->actingAs($admin)->get('/consultations/dashboard')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('openCounts.total', 2)
                ->where('canPick', true)
                ->where('scopeLabel', 'All specialties')
                ->where('specialties', fn ($s) => collect($s)->pluck('name')->sort()->values()->all()
                    === ['Cardiology', 'Nephrology']));

        $this->actingAs($admin)->get("/consultations/dashboard?specialty_id={$nephro->id}")
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('openCounts.total', 1)
                ->where('openCounts.ongoing', 1)
                ->where('scopeLabel', 'Nephrology')
                ->where('filters.specialty_id', $nephro->id));
    }

    public function test_coordinator_may_pick_a_specialty_but_a_plain_consultant_may_not(): void
    {
        $cardio = Specialty::create(['name' => 'Cardiology']);
        $nephro = Specialty::create(['name' => 'Nephrology']);
        $coordinator = $this->user(User::ROLE_REGISTRAR, [
            'specialty_id' => $cardio->id, 'can_coordinate_consultations' => 1,
        ]);
        $doc = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);

        $this->consult(['owning_specialty_id' => $cardio->id, 'status' => Consultation::STATUS_NEW]);
        $this->consult(['owning_specialty_id' => $nephro->id, 'status' => Consultation::STATUS_NEW]);

        $this->actingAs($coordinator)->get("/consultations/dashboard?specialty_id={$nephro->id}")
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('canPick', true)
                ->where('openCounts.total', 1)
                ->where('scopeLabel', 'Nephrology'));

        // a plain consultant's specialty_id parameter is IGNORED — scopeVisibleTo still rules
        $this->actingAs($doc)->get("/consultations/dashboard?specialty_id={$nephro->id}")
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('canPick', false)
                ->where('filters.specialty_id', null)
                ->where('openCounts.total', 1)
                ->where('scopeLabel', 'Cardiology'));
    }

    public function test_ageing_buckets_use_requested_at_falling_back_to_consultation_date(): void
    {
        $cardio = Specialty::create(['name' => 'Cardiology']);
        $doc = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);
        $base = ['owning_specialty_id' => $cardio->id, 'status' => Consultation::STATUS_ACTIVE];

        // 0-2d: requested today, and requested 2 days ago
        $this->consult([...$base, 'requested_at' => now()]);
        $this->consult([...$base, 'requested_at' => now()->subDays(2)]);
        // 3-7d: requested 5 days ago
        $this->consult([...$base, 'requested_at' => now()->subDays(5)]);
        // >7d: a HISTORICAL row — requested_at NULL, so consultation_date is the fallback
        $this->consult([...$base, 'requested_at' => null,
            'consultation_date' => now()->subDays(30)->toDateString()]);
        // unknown: neither date (a legacy row with no consultation_date at all)
        $this->consult([...$base, 'requested_at' => null, 'consultation_date' => null]);
        // signed-off rows never age
        $this->consult([...$base, 'status' => Consultation::STATUS_SIGNED_OFF,
            'requested_at' => now()->subDays(40)]);

        $this->actingAs($doc)->get('/consultations/dashboard')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('ageing.b0_2', 2)
                ->where('ageing.b3_7', 1)
                ->where('ageing.b8_plus', 1)
                ->where('ageing.unknown', 1));
    }

    public function test_soft_deleted_consultations_are_excluded_from_every_count(): void
    {
        $cardio = Specialty::create(['name' => 'Cardiology']);
        $doc = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);

        $this->consult(['owning_specialty_id' => $cardio->id, 'status' => Consultation::STATUS_ACTIVE,
            'requested_at' => now()]);
        $gone = $this->consult(['owning_specialty_id' => $cardio->id, 'status' => Consultation::STATUS_ACTIVE,
            'requested_at' => now()]);
        $gone->delete();

        $this->actingAs($doc)->get('/consultations/dashboard')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('openCounts.active', 1)
                ->where('openCounts.total', 1)
                ->where('ageing.b0_2', 1));
    }
}
