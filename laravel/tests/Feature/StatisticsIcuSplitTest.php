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
 * 2026-09-24 (owner: "optimize this Statistics chart"): the physician drill-down's two destination
 * donuts no longer count an internal ward→ICU move as leaving the department.
 *
 * - "Discharge destinations" (by transfer_type): 'other transfer' is split — to ICU → "Transfer to
 *   ICU", anywhere else → "Out-dept transfer".
 * - "Discharged to" (by discharge_to): 'Intensive Care (ICU)' has its own "ICU" slice instead of
 *   falling into "Transfer".
 *
 * Only the split changes: each donut's total, and every KPI, is exactly what it was.
 */
class StatisticsIcuSplitTest extends TestCase
{
    use RefreshDatabase;

    private function user(int $role, array $extra = []): User
    {
        return User::create(array_merge([
            'username' => 'ic_'.substr(md5(uniqid('', true)), 0, 10),
            'name' => 'IC User', 'password' => 'secret12345', 'role' => $role, 'active' => 1,
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
        ], $extra));
    }

    private function closed(int $consultantId, array $over): Admission
    {
        $p = Patient::create(['mrn' => (string) random_int(10000000, 99999999), 'name' => 'IC Patient']);

        return Admission::create(array_merge([
            'patient_id' => $p->id, 'consultant_id' => $consultantId, 'current_location' => 'Ward',
            'admit_date' => '2024-06-01', 'discharge_date' => '2024-06-05', 'medical_discharge_date' => '2024-06-05',
            'outcome' => 'Alive', 'is_longterm' => 0, 'is_new_assignment' => 0,
        ], $over));
    }

    public function test_a_ward_to_icu_move_is_its_own_slice_and_totals_are_unchanged(): void
    {
        $c = $this->user(User::ROLE_CONSULTANT, ['full_name' => 'Dr Split']);
        $admin = $this->user(User::ROLE_ADMIN);

        // one of each way a non-ICU episode closes
        $this->closed($c->id, ['transfer_type' => 'discharge from ward', 'discharge_to' => 'Home']);
        $this->closed($c->id, ['transfer_type' => 'other transfer', 'discharge_to' => 'Intensive Care (ICU)']);   // ward→ICU
        $this->closed($c->id, ['transfer_type' => 'other transfer', 'discharge_to' => 'Intensive Care (ICU)']);   // ward→ICU
        $this->closed($c->id, ['transfer_type' => 'other transfer', 'discharge_to' => 'General Surgery']);       // left the department
        $this->closed($c->id, ['transfer_type' => 'transfer to other speciality', 'discharge_to' => 'Cardiology']);
        $this->closed($c->id, ['transfer_type' => 'other transfer', 'discharge_to' => null]);                    // legacy, no destination
        // an ICU-located episode stays out of both donuts (non-ICU scope, unchanged)
        $this->closed($c->id, ['current_location' => 'ICU', 'transfer_type' => 'discharge from ICU', 'discharge_to' => 'Home']);

        $this->actingAs($admin)
            ->get("/statistics?from=2024-06-01&to=2024-06-30&consultant_id={$c->id}")
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('physician.destinations.labels', ['Discharged', 'Intra-dept transfer', 'Transfer to ICU', 'Out-dept transfer', 'ICU discharge'])
                // the two ICU moves are no longer "Out-dept"; the external move and the blank one stay there
                ->where('physician.destinations.data', [1, 1, 2, 2, 0])
                ->where('physician.dischargedTo.labels', ['Home', 'Other Facility', 'LAMA', 'Absconded', 'Mortuary', 'ICU', 'Transfer'])
                ->where('physician.dischargedTo.data', [1, 0, 0, 0, 0, 2, 3])
                // unchanged figures: 6 non-ICU closes, 2 moves to ICU
                ->where('physician.numbers.discharges', 6)
                ->where('physician.numbers.transToIcu', 2)
                // each donut still accounts for every non-ICU close exactly once
                ->where('physician.destinations.data', fn ($d) => array_sum(collect($d)->all()) === 6)
                ->where('physician.dischargedTo.data', fn ($d) => array_sum(collect($d)->all()) === 6));
    }

    public function test_an_ended_episode_outside_the_range_or_trashed_is_not_counted(): void
    {
        $c = $this->user(User::ROLE_CONSULTANT, ['full_name' => 'Dr Scope']);
        $admin = $this->user(User::ROLE_ADMIN);
        $this->closed($c->id, ['transfer_type' => 'other transfer', 'discharge_to' => 'Intensive Care (ICU)',
            'admit_date' => '2024-05-01', 'discharge_date' => '2024-05-20', 'medical_discharge_date' => '2024-05-20']);
        $this->closed($c->id, ['transfer_type' => 'other transfer', 'discharge_to' => 'Intensive Care (ICU)'])->delete();

        $this->actingAs($admin)
            ->get("/statistics?from=2024-06-01&to=2024-06-30&consultant_id={$c->id}")
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('physician.destinations.data', [0, 0, 0, 0, 0])
                ->where('physician.dischargedTo.data', [0, 0, 0, 0, 0, 0, 0]));
    }
}
