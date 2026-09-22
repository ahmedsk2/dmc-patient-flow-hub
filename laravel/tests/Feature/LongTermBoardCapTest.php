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
