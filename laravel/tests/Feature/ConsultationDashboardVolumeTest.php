<?php

namespace Tests\Feature;

use App\Models\Consultation;
use App\Models\ConsultationReason;
use App\Models\Specialty;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * W4 — Task 28. Volume trend, top indications, per-consultant load.
 *
 * The trend and the indication tally both key on COALESCE(requested_at, consultation_date):
 * unlike the turnaround medians, VOLUME is not a precision claim, so historical rows legitimately
 * count — they just key off their date-only column.
 *
 * `indication` is a JSON array of consultation_reason ids tallied in PHP, mirroring
 * StatisticsController::index ("consultations by reason (decode JSON indication in PHP — small
 * set)"). Legacy rows store the ids as JSON STRINGS (["1","3"]) while app-written rows store INTs
 * — the Round5 J1-2 lesson — so the tally casts before looking a name up, and this test pins both
 * shapes.
 */
class ConsultationDashboardVolumeTest extends TestCase
{
    use RefreshDatabase;

    private function user(int $role = User::ROLE_CONSULTANT, array $extra = []): User
    {
        return User::create(array_merge([
            'username' => 'cdv_' . substr(md5(uniqid('', true)), 0, 10),
            'name' => 'CDV User', 'password' => 'secret12345', 'role' => $role, 'active' => 1,
            'email_verified_at' => now(),
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
        ], $extra));
    }

    private function consult(array $attrs = []): Consultation
    {
        return Consultation::create(array_merge([
            'mrn' => (string) random_int(10000000, 99999999),
            'patient_name' => 'CDV Patient',
            'consultation_date' => now()->toDateString(),
            'indication' => [],
            'status' => Consultation::STATUS_NEW,
        ], $attrs));
    }

    public function test_volume_trend_buckets_six_months_by_coalesced_request_date(): void
    {
        $cardio = Specialty::create(['name' => 'Cardiology']);
        $doc = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);
        $base = ['owning_specialty_id' => $cardio->id];

        // two this month (one by requested_at, one historical by consultation_date)
        $this->consult([...$base, 'requested_at' => now()]);
        $this->consult([...$base, 'requested_at' => null, 'consultation_date' => now()->toDateString()]);
        // one two months ago
        $this->consult([...$base, 'requested_at' => now()->subMonthsNoOverflow(2)->startOfMonth()->addDays(3)]);
        // one far outside the six-month window — must not appear
        $this->consult([...$base, 'requested_at' => null, 'consultation_date' => now()->subYears(2)->toDateString()]);

        $this->actingAs($doc)->get('/consultations/dashboard')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('trend.labels', fn ($l) => count($l) === 6)
                ->where('trend.data', fn ($d) => count($d) === 6
                    && $d[5] === 2                 // current month
                    && $d[3] === 1                 // two months back
                    && collect($d)->sum() === 3));      // the 2-year-old row is outside the window
    }

    public function test_top_indications_tally_json_ids_in_php_for_both_int_and_string_rows(): void
    {
        $cardio = Specialty::create(['name' => 'Cardiology']);
        $doc = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);
        ConsultationReason::create(['name' => 'Chest pain']);          // id 1
        ConsultationReason::create(['name' => 'Arrhythmia']);          // id 2
        ConsultationReason::create(['name' => 'Heart failure']);       // id 3

        // app-written rows: ids as JSON ints
        $this->consult(['owning_specialty_id' => $cardio->id, 'requested_at' => now(), 'indication' => [1, 2]]);
        $this->consult(['owning_specialty_id' => $cardio->id, 'requested_at' => now(), 'indication' => [1]]);
        // a legacy row: ids as JSON STRINGS, dated by consultation_date
        DB::table('consultations')->insert([
            'mrn' => '77000001', 'patient_name' => 'Legacy Pt',
            'consultation_date' => now()->toDateString(),
            'indication' => '["1","3"]',
            'owning_specialty_id' => $cardio->id,
            'status' => Consultation::STATUS_ONGOING,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($doc)->get('/consultations/dashboard')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('topIndications.0.label', 'Chest pain')
                ->where('topIndications.0.value', 3)
                ->where('topIndications', fn ($rows) => collect($rows)
                    ->firstWhere('label', 'Heart failure')['value'] === 1));
    }

    public function test_per_consultant_load_counts_open_consults_only_within_the_scope(): void
    {
        $cardio = Specialty::create(['name' => 'Cardiology']);
        $nephro = Specialty::create(['name' => 'Nephrology']);
        $doc = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);
        $busy = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id, 'full_name' => 'Dr Busy']);
        $quiet = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id, 'full_name' => 'Dr Quiet']);
        $other = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $nephro->id, 'full_name' => 'Dr Other']);

        $this->consult(['owning_specialty_id' => $cardio->id, 'consultant_id' => $busy->id, 'status' => Consultation::STATUS_ACTIVE]);
        $this->consult(['owning_specialty_id' => $cardio->id, 'consultant_id' => $busy->id, 'status' => Consultation::STATUS_ONGOING]);
        $this->consult(['owning_specialty_id' => $cardio->id, 'consultant_id' => $quiet->id, 'status' => Consultation::STATUS_NEW]);
        // signed off — closed work is not "load"
        $this->consult(['owning_specialty_id' => $cardio->id, 'consultant_id' => $quiet->id,
            'status' => Consultation::STATUS_SIGNED_OFF, 'signoff_date' => now()->toDateString()]);
        // another specialty — outside the scope
        $this->consult(['owning_specialty_id' => $nephro->id, 'consultant_id' => $other->id, 'status' => Consultation::STATUS_ACTIVE]);
        // unassigned — no consultant, so no row on the load list
        $this->consult(['owning_specialty_id' => $cardio->id, 'consultant_id' => null, 'status' => Consultation::STATUS_ACTIVE]);

        $this->assertNotNull($doc->id);

        $this->actingAs($doc)->get('/consultations/dashboard')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('perConsultant', fn ($rows) => count($rows) === 2
                    && $rows[0]['name'] === 'Dr Busy' && $rows[0]['c'] === 2
                    && $rows[1]['name'] === 'Dr Quiet' && $rows[1]['c'] === 1));
    }

    public function test_soft_deleted_rows_are_excluded_from_trend_indications_and_load(): void
    {
        $cardio = Specialty::create(['name' => 'Cardiology']);
        $doc = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);
        $cons = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id, 'full_name' => 'Dr Load']);
        // Fetch the id dynamically rather than hard-coding it: MySQL InnoDB does not reclaim
        // AUTO_INCREMENT values on a rolled-back transaction, so an earlier test in this class
        // (test_top_indications...) having already inserted rows leaves this row's real id
        // higher than 1 by the time this test runs.
        $reason = ConsultationReason::create(['name' => 'Chest pain']);

        $this->consult(['owning_specialty_id' => $cardio->id, 'consultant_id' => $cons->id,
            'status' => Consultation::STATUS_ACTIVE, 'requested_at' => now(), 'indication' => [$reason->id]]);
        $gone = $this->consult(['owning_specialty_id' => $cardio->id, 'consultant_id' => $cons->id,
            'status' => Consultation::STATUS_ACTIVE, 'requested_at' => now(), 'indication' => [$reason->id]]);
        $gone->delete();

        $this->actingAs($doc)->get('/consultations/dashboard')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('trend.data', fn ($d) => collect($d)->sum() === 1)
                ->where('topIndications.0.value', 1)
                ->where('perConsultant.0.c', 1));
    }
}
