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
 * W4 — Task 27. The two turnaround medians.
 *
 * THE LOAD-BEARING RULE: every historical row has requested_at NULL (design §4.4 — fabricating a
 * time from a date-only legacy column would manufacture precision that never existed). Both
 * medians therefore filter `requested_at IS NOT NULL`, and the payload carries `legacy_excluded`
 * so the UI can state "from cutover — N historical consultations excluded" instead of silently
 * averaging in invented numbers.
 *
 * Follow-ups are inserted with DB::table() so created_at can be set to a real past instant
 * regardless of how the ConsultationFollowup model configures its append-only timestamp.
 */
class ConsultationDashboardTurnaroundTest extends TestCase
{
    use RefreshDatabase;

    private function user(int $role = User::ROLE_CONSULTANT, array $extra = []): User
    {
        return User::create(array_merge([
            'username' => 'cdt_'.substr(md5(uniqid('', true)), 0, 10),
            'name' => 'CDT User', 'password' => 'secret12345', 'role' => $role, 'active' => 1,
            'email_verified_at' => now(),
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
        ], $extra));
    }

    private function consult(array $attrs = []): Consultation
    {
        return Consultation::create(array_merge([
            'mrn' => (string) random_int(10000000, 99999999),
            'patient_name' => 'CDT Patient',
            'consultation_date' => now()->toDateString(),
            'indication' => [],
            'status' => Consultation::STATUS_NEW,
        ], $attrs));
    }

    /**
     * A numeric-equality expectation for an hours median.
     *
     * The payload value IS a float rounded to one decimal (see the controller's medianHours()), but
     * json_encode drops a whole number's zero fraction — 4.0 is serialised as `4` — and the Inertia
     * assertion decodes the page JSON before comparing with assertSame(). A literal `4.0` therefore
     * could never match a whole-hour median, no matter what the controller returns. The VALUE is
     * what this test pins, so compare it numerically.
     */
    private function hours(float $expected): \Closure
    {
        return fn ($actual) => is_numeric($actual) && abs((float) $actual - $expected) < 0.001;
    }

    private function followupAt(Consultation $c, string $date, string $createdAt): void
    {
        DB::table('consultation_followups')->insert([
            'consultation_id' => $c->id,
            'followup_date' => $date,
            'note' => null,
            'author_id' => null,
            'created_at' => $createdAt,
        ]);
    }

    /**
     * Hand-computed fixture (Cardiology):
     *   A  requested 10h ago, signed off 4h ago                -> sign-off  6h
     *   B  requested 20h ago, signed off 18h ago               -> sign-off  2h
     *   C  HISTORICAL, closed before cutover: no timestamps    -> EXCLUDED
     *   C2 HISTORICAL, signed off AFTER cutover: real end,     -> EXCLUDED
     *      no start (requested_at NULL, signed_off_at = now)
     * median sign-off over [2, 6] = 4.0h; legacy_excluded = 2 (C and C2).
     *
     * C2 is the row this test is really named for, and the one that will exist ~1,283 times within
     * weeks of cutover: ConsultationsController::signoff() writes `signed_off_at = now()` and never
     * backfills `requested_at`, so every legacy consult closed from today onward has a real end
     * instant and no start instant. It is the ONLY fixture shape that can distinguish "exclude the
     * row" from "substitute consultation_date for the missing request time" — the exact §4.4
     * violation an engineer would introduce by harmonising turnaround() with ageing()'s
     * COALESCE(requested_at, consultation_date). Under that mutation C2 contributes the whole span
     * from its 2024 consultation_date to now, the sample becomes [2h, 6h, ~2 years] and this reads
     * 6.0h over n = 3 instead of 4.0h over n = 2 — a plausible-looking number that is entirely
     * fabricated. C alone cannot catch it: with BOTH timestamps NULL, TIMESTAMPDIFF is NULL and the
     * row falls out on its own no matter what the query says, which is why the pre-review fixture
     * left the guard unpinned.
     */
    public function test_median_time_to_signoff_excludes_historical_null_requested_at_rows(): void
    {
        $cardio = Specialty::create(['name' => 'Cardiology']);
        $doc = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);
        $base = ['owning_specialty_id' => $cardio->id, 'status' => Consultation::STATUS_SIGNED_OFF];

        $this->consult([...$base,
            'requested_at' => now()->subHours(10), 'signed_off_at' => now()->subHours(4),
            'signoff_date' => now()->toDateString()]);
        $this->consult([...$base,
            'requested_at' => now()->subHours(20), 'signed_off_at' => now()->subHours(18),
            'signoff_date' => now()->toDateString()]);
        // C — the legacy shape closed before cutover: date-only sign-off, no real timestamps at all
        $this->consult([...$base,
            'requested_at' => null, 'signed_off_at' => null,
            'consultation_date' => '2024-01-01', 'signoff_date' => '2024-01-02']);
        // C2 — the legacy shape closed AFTER cutover: a real signed_off_at, still no requested_at
        $this->consult([...$base,
            'requested_at' => null, 'signed_off_at' => now(),
            'consultation_date' => '2024-01-01', 'signoff_date' => now()->toDateString()]);

        $this->actingAs($doc)->get('/consultations/dashboard')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('turnaround.signoff_hours', $this->hours(4.0))
                ->where('turnaround.signoff_n', 2)
                ->where('turnaround.legacy_excluded', 2)
                ->where('turnaround.from_cutover', true));
    }

    /**
     * First-follow-up fixture (Cardiology):
     *   D  requested 12h ago, first tick 9h ago, second tick 1h ago -> 3h (the FIRST tick counts)
     *   E  requested  8h ago, first tick 3h ago                     -> 5h
     *   F  HISTORICAL: requested_at NULL, ticked 2h ago             -> EXCLUDED
     *   G  requested 4h ago, never ticked                           -> not in the sample
     * median over [3, 5] = 4.0h, n = 2.
     */
    public function test_median_time_to_first_followup_uses_the_earliest_tick_and_skips_legacy_rows(): void
    {
        $cardio = Specialty::create(['name' => 'Cardiology']);
        $doc = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);
        $base = ['owning_specialty_id' => $cardio->id, 'status' => Consultation::STATUS_ACTIVE];

        $d = $this->consult([...$base, 'requested_at' => now()->subHours(12)]);
        $this->followupAt($d, now()->subDay()->toDateString(), now()->subHours(9)->toDateTimeString());
        $this->followupAt($d, now()->toDateString(), now()->subHours(1)->toDateTimeString());

        $e = $this->consult([...$base, 'requested_at' => now()->subHours(8)]);
        $this->followupAt($e, now()->toDateString(), now()->subHours(3)->toDateTimeString());

        $f = $this->consult([...$base, 'requested_at' => null, 'consultation_date' => '2024-01-01']);
        $this->followupAt($f, now()->toDateString(), now()->subHours(2)->toDateTimeString());

        $this->consult([...$base, 'requested_at' => now()->subHours(4)]);   // never ticked

        $this->actingAs($doc)->get('/consultations/dashboard')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('turnaround.first_followup_hours', $this->hours(4.0))
                ->where('turnaround.first_followup_n', 2));
    }

    public function test_an_all_historical_scope_reports_null_medians_and_the_excluded_count(): void
    {
        $cardio = Specialty::create(['name' => 'Cardiology']);
        $doc = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);

        // exactly the shape of the 1,283 imported rows: dates only, no timestamps
        $this->consult(['owning_specialty_id' => $cardio->id, 'status' => Consultation::STATUS_ONGOING,
            'requested_at' => null, 'consultation_date' => '2024-03-01']);
        $this->consult(['owning_specialty_id' => $cardio->id, 'status' => Consultation::STATUS_SIGNED_OFF,
            'requested_at' => null, 'consultation_date' => '2024-03-02', 'signoff_date' => '2024-03-05']);

        $this->actingAs($doc)->get('/consultations/dashboard')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('turnaround.first_followup_hours', null)
                ->where('turnaround.first_followup_n', 0)
                ->where('turnaround.signoff_hours', null)
                ->where('turnaround.signoff_n', 0)
                ->where('turnaround.legacy_excluded', 2));
    }

    public function test_medians_are_scoped_and_exclude_soft_deleted_rows(): void
    {
        $cardio = Specialty::create(['name' => 'Cardiology']);
        $nephro = Specialty::create(['name' => 'Nephrology']);
        $doc = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $cardio->id]);
        $signed = ['status' => Consultation::STATUS_SIGNED_OFF, 'signoff_date' => now()->toDateString()];

        // in scope: 6h
        $this->consult([...$signed, 'owning_specialty_id' => $cardio->id,
            'requested_at' => now()->subHours(10), 'signed_off_at' => now()->subHours(4)]);
        // other specialty: 100h — must not move the median
        $this->consult([...$signed, 'owning_specialty_id' => $nephro->id,
            'requested_at' => now()->subHours(110), 'signed_off_at' => now()->subHours(10)]);
        // soft-deleted, in scope: 200h — must not move the median either
        $trashed = $this->consult([...$signed, 'owning_specialty_id' => $cardio->id,
            'requested_at' => now()->subHours(210), 'signed_off_at' => now()->subHours(10)]);
        $trashed->delete();

        $this->actingAs($doc)->get('/consultations/dashboard')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('turnaround.signoff_hours', $this->hours(6.0))
                ->where('turnaround.signoff_n', 1));
    }
}
