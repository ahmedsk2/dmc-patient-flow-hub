<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\Patient;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * PERF-03 (prod-ready 2026-09-03): the long-term registry is the ONE board view that lists closed
 * episodes, so it is the one board query with no natural bound — every other view is bounded by
 * `discharge_date IS NULL`, i.e. by ward capacity. PatientsController caps it, keeps the NEWEST
 * episodes, and reports the cap to the page so a trimmed list never passes for a complete one.
 *
 * Production held 176 long-term rows on 2026-09-03, so the real cap (1000) is a guard against the
 * decade rather than today's behaviour. These tests drive the capped path through
 * `config('board.longterm_row_cap')` instead of inserting a thousand admissions.
 */
class LongTermBoardCapTest extends TestCase
{
    use RefreshDatabase;

    private function user(int $role = User::ROLE_CONSULTANT, array $extra = []): User
    {
        return User::create(array_merge([
            'username' => 'ltc_'.substr(md5(uniqid('', true)), 0, 10),
            'name' => 'LT User', 'password' => 'secret12345', 'role' => $role, 'active' => 1,
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(), 'email_verified_at' => now(),
        ], $extra));
    }

    /** Three long-term episodes for one consultant: two old + closed, one recent + still open. */
    private function threeLongTermEpisodes(User $consultant): array
    {
        $mk = function (string $admit, ?string $discharge) use ($consultant) {
            $p = Patient::create(['mrn' => (string) random_int(10000000, 99999999), 'name' => 'LT Patient']);

            return Admission::create(array_filter([
                'patient_id' => $p->id,
                'consultant_id' => $consultant->id,
                'admit_date' => $admit,
                'current_location' => 'Ward',
                'is_longterm' => 1,
                'is_new_assignment' => 0,
                'discharge_date' => $discharge,
                'transfer_type' => $discharge ? 'discharge from ward' : null,
                'outcome' => $discharge ? 'Alive' : null,
            ], fn ($v) => $v !== null));
        };

        return [
            'oldest' => $mk('2022-01-03', '2022-02-01'),
            'middle' => $mk('2023-06-01', '2023-07-01'),
            'newest' => $mk(now()->subDays(5)->toDateString(), null),
        ];
    }

    public function test_the_long_term_registry_is_capped_and_reports_the_cap(): void
    {
        config(['board.longterm_row_cap' => 2]);
        $rows = $this->threeLongTermEpisodes($this->user());

        $this->actingAs($this->user(User::ROLE_ADMIN))->get('/patients?view=longterm')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('truncated.shown', 2)
                ->where('truncated.total', 3)
                ->where('groups.0.patients', function ($patients) use ($rows) {
                    $ids = collect($patients)->pluck('id')->all();

                    // the OLDEST closed episode is the one dropped, never the newest
                    return $ids === [$rows['middle']->id, $rows['newest']->id];
                }));
    }

    public function test_an_uncapped_registry_reports_no_truncation_and_lists_everything(): void
    {
        config(['board.longterm_row_cap' => 10]);
        $rows = $this->threeLongTermEpisodes($this->user());

        $this->actingAs($this->user(User::ROLE_ADMIN))->get('/patients?view=longterm')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('truncated', null)
                ->where('groups.0.patients', fn ($patients) => collect($patients)->pluck('id')->all()
                    === [$rows['oldest']->id, $rows['middle']->id, $rows['newest']->id]));
    }

    /**
     * The one that matters clinically. A long-term patient who is STILL IN A BED has one of the
     * OLDEST admit_dates on this view — that is what makes them long-term — so a cap that simply
     * kept the newest admit_dates would drop the live patients FIRST and leave a registry of
     * nothing but discharged ones. Open episodes sort ahead of closed ones and are never trimmed.
     *
     * This test fails against an `orderByDesc('admit_date')`-only cap.
     */
    public function test_a_patient_still_admitted_is_never_trimmed_however_old_the_admission(): void
    {
        config(['board.longterm_row_cap' => 2]);
        $c = $this->user();

        $mk = function (string $admit, ?string $discharge) use ($c) {
            $p = Patient::create(['mrn' => (string) random_int(10000000, 99999999), 'name' => 'LT Patient']);

            return Admission::create(array_filter([
                'patient_id' => $p->id, 'consultant_id' => $c->id, 'admit_date' => $admit,
                'current_location' => 'Ward', 'is_longterm' => 1, 'is_new_assignment' => 0,
                'discharge_date' => $discharge,
                'transfer_type' => $discharge ? 'discharge from ward' : null,
                'outcome' => $discharge ? 'Alive' : null,
            ], fn ($v) => $v !== null));
        };

        // the long-stay patient: admitted years before either closed episode, still in a bed
        $stillIn = $mk('2021-03-01', null);
        $mk('2024-01-01', '2024-02-01');
        $newestClosed = $mk('2025-01-01', '2025-02-01');

        $this->actingAs($this->user(User::ROLE_ADMIN))->get('/patients?view=longterm')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('truncated.shown', 2)
                ->where('truncated.total', 3)
                ->where('groups.0.patients', function ($patients) use ($stillIn, $newestClosed) {
                    $ids = collect($patients)->pluck('id')->all();

                    // still-admitted kept despite being the oldest; the MIDDLE closed one is dropped
                    return $ids === [$stillIn->id, $newestClosed->id];
                }));
    }

    /**
     * The cap exists only because view=longterm includes closed episodes. Every other view is
     * already bounded, so it must never be trimmed — even with the cap set below the row count.
     */
    public function test_the_default_board_is_never_capped(): void
    {
        config(['board.longterm_row_cap' => 1]);
        $rows = $this->threeLongTermEpisodes($this->user());

        $this->actingAs($this->user(User::ROLE_ADMIN))->get('/patients')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('truncated', null)
                // only the open episode matches the default board, and it is present
                ->where('groups.0.patients', fn ($patients) => collect($patients)->pluck('id')->all()
                    === [$rows['newest']->id]));
    }
}
