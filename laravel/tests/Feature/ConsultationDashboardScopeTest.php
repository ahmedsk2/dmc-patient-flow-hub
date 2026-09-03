<?php

namespace Tests\Feature;

use App\Models\Consultation;
use App\Models\Specialty;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * W4 — Task 25. GET /consultations/dashboard is a PHYSICIAN route (ordinary auth group), not an
 * admin one: Statistics / Registry / Reports stay admin-only and are untouched. Scoping is
 * Consultation::scopeVisibleTo(); admins + coordinators may narrow to one specialty with
 * ?specialty_id=N. Observers are refused BEFORE any capability flag is consulted.
 *
 * Metrics asserted here:
 *   openCounts — COUNT(*) GROUP BY status over rows still on the books
 *   ageing     — DATEDIFF(<the app's today>, DATE(COALESCE(requested_at, consultation_date)))
 *                bucketed 0-2d / 3-7d / >7d, rows lacking BOTH dates reported as 'unknown'
 *
 * The last four methods were added in review: they pin the day-boundary source of truth, the
 * status/signoff_date drift belt, an unknown status value, and the cross-specialty widening that
 * scopeVisibleTo deliberately performs.
 */
class ConsultationDashboardScopeTest extends TestCase
{
    use RefreshDatabase;

    private function user(int $role = User::ROLE_CONSULTANT, array $extra = []): User
    {
        return User::create(array_merge([
            'username' => 'cds_'.substr(md5(uniqid('', true)), 0, 10),
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

    /**
     * REVIEW FIX — the day boundary must be the APP's day, not the database host's.
     *
     * ConsultationsController::openDays() (the ONE ageing rule, shared by the workspace row and the
     * printed handover sheet) compares against PHP's now()->startOfDay(), i.e. config('app.timezone')
     * = Asia/Riyadh. config/database.php pins no session timezone on the mysql connection, so
     * MySQL's CURDATE() is the DB host's day — UTC on the deployment box. Between 00:00 and 03:00
     * local the two disagree by a full day, and that is exactly when the night shift prints the
     * sheet: a consult openDays() calls 8 days ("escalate") would have been filed under b3_7 here,
     * with the "Over 7 days" tile reading 0.
     *
     * This is unobservable through the response on a dev box (WAMP's MySQL shares the host clock),
     * so it is pinned at the only reachable place: the emitted SQL must not ask the database what
     * day it is, and today's date must arrive as a binding.
     */
    public function test_ageing_binds_the_apps_today_and_never_asks_mysql_for_curdate(): void
    {
        $cardio = Specialty::create(['name' => 'Cardiology']);
        $doc = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);
        $this->consult(['owning_specialty_id' => $cardio->id,
            'status' => Consultation::STATUS_ACTIVE, 'requested_at' => now()->subDays(9)]);

        $ageingQueries = [];
        DB::listen(function ($q) use (&$ageingQueries) {
            if (str_contains($q->sql, 'DATEDIFF')) {
                $ageingQueries[] = $q;
            }
        });

        $this->actingAs($doc)->get('/consultations/dashboard')
            ->assertInertia(fn (AssertableInertia $p) => $p->where('ageing.b8_plus', 1));

        $this->assertCount(1, $ageingQueries, 'expected exactly one ageing query');
        $this->assertStringNotContainsString('CURDATE', $ageingQueries[0]->sql,
            'the ageing window must not be evaluated in the MySQL session timezone');
        $this->assertContains(now()->toDateString(), $ageingQueries[0]->bindings,
            "the app's today must be bound into the ageing query");
    }

    /**
     * REVIEW FIX — the status/signoff_date drift belt the handover sheet already carries.
     *
     * legacy:import writes signoff_date without status, so after a reload with the source-of-truth
     * flag off, closed legacy consults return as status='new'. Without this clause they headline
     * this page as open work and, being years old, all land in the >7-day escalation bucket — while
     * the printed sheet (which checks signoff_date) correctly ignores them.
     */
    public function test_a_closed_legacy_row_whose_status_never_migrated_is_not_counted_as_open(): void
    {
        $cardio = Specialty::create(['name' => 'Cardiology']);
        $doc = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);

        $this->consult(['owning_specialty_id' => $cardio->id,
            'status' => Consultation::STATUS_ACTIVE, 'requested_at' => now()]);
        // The drifted row: signed off two years ago, status still says 'new'.
        $this->consult(['owning_specialty_id' => $cardio->id,
            'status' => Consultation::STATUS_NEW,
            'consultation_date' => now()->subDays(700)->toDateString(),
            'signoff_date' => now()->subDays(699)->toDateString()]);

        $this->actingAs($doc)->get('/consultations/dashboard')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('openCounts.new', 0)
                ->where('openCounts.total', 1)
                ->where('ageing.b0_2', 1)
                ->where('ageing.b8_plus', 0));
    }

    /**
     * REVIEW FIX — `total` must never silently under-report.
     *
     * `status` is a plain string column with no CHECK constraint; a hand-run SQL fix on prod (this
     * team does those) or a future state could leave a value outside the three known ones. Such a
     * row is still counted by the ageing buckets, so deriving `total` from new+active+ongoing made
     * the two tiles contradict each other with no signal, in the under-reporting direction.
     */
    public function test_total_counts_every_open_row_even_one_with_an_unrecognised_status(): void
    {
        $cardio = Specialty::create(['name' => 'Cardiology']);
        $doc = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);

        $this->consult(['owning_specialty_id' => $cardio->id,
            'status' => Consultation::STATUS_NEW, 'requested_at' => now()]);
        $this->consult(['owning_specialty_id' => $cardio->id,
            'status' => 'escalated', 'requested_at' => now()]);

        $this->actingAs($doc)->get('/consultations/dashboard')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('openCounts.new', 1)
                ->where('openCounts.active', 0)
                ->where('openCounts.ongoing', 0)
                // total agrees with the ageing buckets, which counted both rows.
                ->where('openCounts.total', 2)
                ->where('ageing.b0_2', 2));
    }

    /**
     * REVIEW FIX (characterisation) — scopeVisibleTo deliberately widens past the viewer's own
     * specialty: a consult they booked into ANOTHER team's book stays visible to them, and is
     * therefore counted here, under a scopeLabel that names only their own specialty.
     *
     * That is an UNDER-statement by the label, never an over-statement — no row is hidden behind a
     * label claiming to exclude it — and it is the documented rule, so it is pinned rather than
     * changed. test_consultant_sees_only_their_own_specialty_counts never creates such a row, so
     * without this method the widening is unpinned at this surface.
     */
    public function test_a_consult_the_viewer_booked_into_another_specialty_is_still_counted(): void
    {
        $cardio = Specialty::create(['name' => 'Cardiology']);
        $nephro = Specialty::create(['name' => 'Nephrology']);
        $doc = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);

        $this->consult(['owning_specialty_id' => $cardio->id, 'status' => Consultation::STATUS_NEW]);
        $this->consult(['owning_specialty_id' => $nephro->id,
            'status' => Consultation::STATUS_ONGOING, 'entered_by' => $doc->id]);

        $this->actingAs($doc)->get('/consultations/dashboard')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('openCounts.new', 1)
                ->where('openCounts.ongoing', 1)
                ->where('openCounts.total', 2)
                ->where('scopeLabel', 'Cardiology'));
    }
}
