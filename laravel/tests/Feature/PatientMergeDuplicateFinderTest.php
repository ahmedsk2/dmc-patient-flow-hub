<?php

namespace Tests\Feature;

use App\Models\Patient;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * 2026-09-23 UAT: the possible-duplicates finder on Admin -> Patient Merge self-joined `patients` on
 * computed expressions and took 57 s on the production volume (17k patients) — past the 60 s web
 * SELECT cap. It now finds candidate keys in one grouped pass and restricts the (unchanged) pair
 * queries to those keys; on production the result set was identical and the time 116 ms. These tests
 * pin the pairs it must still find and that the expensive join never runs when there is nothing to pair.
 */
class PatientMergeDuplicateFinderTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'username' => 'dupe_'.substr(md5(uniqid('', true)), 0, 10), 'name' => 'Dupe Admin', 'password' => 'secret12345',
            'role' => User::ROLE_ADMIN, 'active' => 1, 'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
            'email' => 'dupe_'.substr(md5(uniqid('', true)), 0, 8).'@example.test', 'email_verified_at' => now(),
            'pass_exp_date' => now()->toDateString(),
        ]);
    }

    private function patient(string $mrn, string $name): Patient
    {
        return Patient::create(['mrn' => $mrn, 'name' => $name, 'age' => 40, 'gender' => 'Male']);
    }

    /** @return array<int, string> unordered id pairs, e.g. ["3-7"] */
    private function pairs(): array
    {
        $pairs = [];
        $this->actingAs($this->admin())->get('/admin/patient-merge')->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$pairs) {
                $page->where('possibleDuplicates', function ($dupes) use (&$pairs) {
                    $pairs = collect($dupes)->map(fn ($d) => min((int) $d['id1'], (int) $d['id2']).'-'.max((int) $d['id1'], (int) $d['id2']))
                        ->values()->all();   // page order — the finder orders pairs by id

                    return true;
                });
            });

        return $pairs;
    }

    public function test_every_pair_inside_one_normalised_mrn_group_is_listed(): void
    {
        $a = $this->patient('00777', 'Group A');
        $b = $this->patient('0777', 'Group B');
        $c = $this->patient('777', 'Group C');
        $this->patient('888', 'Unrelated');

        $this->assertSame(["{$a->id}-{$b->id}", "{$a->id}-{$c->id}", "{$b->id}-{$c->id}"], $this->pairs());
    }

    public function test_the_placeholder_name_match_keeps_the_databases_case_and_accent_insensitive_matching(): void
    {
        $placeholder = $this->patient('NOMRN-5001', 'JOSE ALI');
        $real = $this->patient('4005001', 'José Ali');
        $this->patient('NOMRN-5002', 'Nobody Else');

        $this->assertSame([min($placeholder->id, $real->id).'-'.max($placeholder->id, $real->id)], $this->pairs());
    }

    /** Review fix: no cap on the candidate-key pass (it dropped whole groups); the pair list keeps its 50, in id order. */
    public function test_more_than_fifty_groups_still_fill_the_list_in_a_stable_order(): void
    {
        $expected = [];
        foreach (range(1, 51) as $i) {
            $a = $this->patient('0'.(7100 + $i), "Pair {$i} A");
            $b = $this->patient((string) (7100 + $i), "Pair {$i} B");
            $expected[] = "{$a->id}-{$b->id}";
        }

        $pairs = $this->pairs();
        $this->assertCount(50, $pairs);
        $this->assertSame(array_slice($expected, 0, 50), $pairs);
    }

    public function test_soft_deleted_patients_are_never_paired(): void
    {
        $this->patient('00999', 'Live');
        $this->patient('999', 'Gone')->delete();

        $this->assertSame([], $this->pairs());
    }

    public function test_the_expensive_self_join_does_not_run_when_there_is_nothing_to_pair(): void
    {
        foreach (range(1, 20) as $i) {
            $this->patient((string) (5000000 + $i), "Distinct {$i}");
        }
        $joins = 0;
        DB::listen(function ($q) use (&$joins) {
            if (preg_match('/from `patients` as `(p1|ph)`/i', $q->sql)) {
                $joins++;
            }
        });

        $this->assertSame([], $this->pairs());
        $this->assertSame(0, $joins, 'no candidate keys, so no patients-to-patients join');
    }
}
