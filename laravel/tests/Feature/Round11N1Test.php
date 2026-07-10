<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\Icd10;
use App\Models\Patient;
use App\Models\TbDiagnosis;
use App\Models\User;
use App\Services\ShuffleService;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Round-11 fix batch N1 (code-change findings):
 *  1. ModifyAdmissionRequest consultant-role rule applies ONLY when consultant_id CHANGED — a
 *     registrar/resident-held patient (assign-to-me, K1-9) stays editable for unrelated corrections.
 *  3. ShuffleService TB-load asymmetry: TB counts toward a SUBSPECIALIST's load but not a
 *     HOSPITALIST's (legacy dmc-patients-shuffle.php hospitalist vs subspecialist counting).
 *  4. Discharge destination + delay required: discharge_to required when CLOSING the file
 *     (complete / icu / one-step, unless Dead auto-forces Mortuary); delay_reason required on a
 *     medical-only (still-in) discharge.
 *  7. Assign targets must be ACTIVE CONSULTANTS (assign, bulkReassign, specialty-transfer) — not a
 *     bare exists:users,id an API caller could point at an inactive/non-consultant user.
 *  8. Per-consultant readmissions credit a SINGLE anchor (the most-recent qualifying prior
 *     discharge's consultant) so the per-consultant column sums to the headline distinct count.
 */
class Round11N1Test extends TestCase
{
    use RefreshDatabase;

    private function user(int $role = User::ROLE_CONSULTANT, array $extra = []): User
    {
        return User::create(array_merge([
            'username' => 'n1_' . substr(md5(uniqid('', true)), 0, 10),
            'name' => 'N1 User', 'password' => 'secret12345', 'role' => $role, 'active' => 1,
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
        ], $extra));
    }

    private function admin(): User
    {
        return $this->user(User::ROLE_ADMIN);
    }

    private function consultant(string $name, int $specialty = 1, bool $onService = true): User
    {
        return $this->user(User::ROLE_CONSULTANT, [
            'full_name' => $name, 'on_service' => $onService ? 1 : 0, 'specialty_id' => $specialty,
        ]);
    }

    private function admission(array $overrides = [], ?Patient $patient = null): Admission
    {
        $p = $patient ?? Patient::create(['mrn' => (string) random_int(10000000, 99999999), 'name' => 'N1 Patient']);

        return Admission::create(array_merge([
            'patient_id' => $p->id, 'admit_date' => now()->subDays(3)->toDateString(),
            'current_location' => 'Ward', 'is_longterm' => 0, 'is_new_assignment' => 0,
        ], $overrides));
    }

    /** A full Modify payload mirroring what the modal round-trips. */
    private function modifyPayload(Admission $a, array $overrides = []): array
    {
        $p = $a->patient;

        return array_merge([
            'mrn' => $p->mrn, 'name' => $p->name, 'age' => $p->age, 'gender' => $p->gender,
            'nationality' => $p->nationality, 'bed' => $a->bed, 'admit_date' => $a->admit_date->toDateString(),
            'admitted_from' => $a->admitted_from, 'current_location' => $a->current_location,
            'consultant_id' => $a->consultant_id, 'diagnoses' => [],
        ], $overrides);
    }

    // ---- 1. modify consultant_id validate-on-change ---------------------------------------------

    public function test_editing_a_registrar_held_patient_without_touching_consultant_saves(): void
    {
        // a patient legitimately held by a REGISTRAR (assign-to-me lets non-consultants hold)
        $registrar = $this->user(User::ROLE_REGISTRAR);
        $a = $this->admission(['consultant_id' => $registrar->id, 'bed' => 'A1']);

        // an unrelated bed correction round-trips the registrar's id as consultant_id — must pass
        $this->actingAs($this->admin())->from('/patients')
            ->post("/admissions/{$a->id}/modify", $this->modifyPayload($a, ['bed' => 'B2']))
            ->assertRedirect('/patients')->assertSessionHasNoErrors();

        $this->assertSame('B2', $a->fresh()->bed);
        $this->assertSame($registrar->id, (int) $a->fresh()->consultant_id, 'the assignee must be untouched');
    }

    public function test_changing_consultant_to_a_non_consultant_is_rejected(): void
    {
        $a = $this->admission(['consultant_id' => $this->consultant('Holder')->id]);
        $resident = $this->user(User::ROLE_RESIDENT);

        $this->actingAs($this->admin())->from('/patients')
            ->post("/admissions/{$a->id}/modify", $this->modifyPayload($a, ['consultant_id' => $resident->id]))
            ->assertRedirect('/patients')->assertSessionHasErrors('consultant_id');
    }

    public function test_changing_consultant_to_a_valid_consultant_works(): void
    {
        $a = $this->admission(['consultant_id' => $this->consultant('From')->id]);
        $to = $this->consultant('To');

        $this->actingAs($this->admin())->from('/patients')
            ->post("/admissions/{$a->id}/modify", $this->modifyPayload($a, ['consultant_id' => $to->id]))
            ->assertRedirect('/patients')->assertSessionHasNoErrors();

        $this->assertSame($to->id, (int) $a->fresh()->consultant_id);
    }

    // ---- 3. shuffle TB-load asymmetry -----------------------------------------------------------

    public function test_tb_counts_toward_a_subspecialist_load_but_not_a_hospitalist(): void
    {
        Icd10::create(['code' => 'A15.0', 'name' => 'Tuberculosis of lung']);
        TbDiagnosis::create(['icd10_code' => 'A15.0']);

        // settings: min 0, max 5 for both tiers so the load tie-break decides placement
        \App\Models\Setting::current()->update([
            'min_hospitalist' => 0, 'max_hospitalist' => 5, 'min_subs' => 0, 'max_subs' => 5,
        ]);

        $hosp = $this->consultant('Hosp', 1);   // lower id -> wins ties when equal
        $sub = $this->consultant('Sub', 2);

        // each already holds 2 TB patients. For the hospitalist those are EXCLUDED (load 0);
        // for the sub they COUNT (load 2). So the next ward patient must land on the hospitalist.
        foreach (['hosp' => $hosp, 'sub' => $sub] as $c) {
            for ($i = 0; $i < 2; $i++) {
                $tb = $this->admission(['consultant_id' => $c->id]);
                $tb->diagnoses()->create(['seq' => 1, 'icd10_code' => 'A15.0']);
            }
        }
        $this->admission();   // one plain ward patient to place

        (new ShuffleService)->run();

        // the sub's TB load (2) made it look more loaded than the hospitalist (0) -> hospitalist wins
        $this->assertSame(3, Admission::where('consultant_id', $hosp->id)->count(), 'hospitalist gets the new patient (TB off its load)');
        $this->assertSame(2, Admission::where('consultant_id', $sub->id)->count(), 'sub stays at its TB-inclusive load');
    }

    // ---- 4. discharge destination + delay required ----------------------------------------------

    public function test_complete_discharge_without_destination_is_rejected(): void
    {
        $a = $this->admission(['medical_discharge_date' => now()->toDateString(), 'outcome' => 'Alive']);

        $this->actingAs($this->admin())->from('/patients')
            ->post("/admissions/{$a->id}/complete-discharge", ['discharge_date' => now()->toDateString()])
            ->assertRedirect('/patients')->assertSessionHasErrors('discharge_to');
        $this->assertNull($a->fresh()->discharge_date);
    }

    public function test_one_step_discharge_without_destination_is_rejected(): void
    {
        $a = $this->admission();

        $this->actingAs($this->admin())->from('/patients')
            ->post("/admissions/{$a->id}/medical-discharge", [
                'outcome' => 'Alive', 'medical_discharge_date' => now()->toDateString(), 'complete' => true,
            ])->assertRedirect('/patients')->assertSessionHasErrors('discharge_to');
        $this->assertNull($a->fresh()->discharge_date);
    }

    public function test_icu_discharge_without_destination_is_rejected(): void
    {
        $a = $this->admission(['current_location' => 'ICU']);

        $this->actingAs($this->admin())->from('/patients')
            ->post("/admissions/{$a->id}/icu-discharge", [
                'outcome' => 'Alive', 'discharge_date' => now()->toDateString(),
            ])->assertRedirect('/patients')->assertSessionHasErrors('discharge_to');
        $this->assertNull($a->fresh()->discharge_date);
    }

    public function test_dead_close_needs_no_destination_mortuary_is_forced(): void
    {
        // Dead auto-forces Mortuary, so the destination is NOT required when the outcome is Dead
        $a = $this->admission();
        $this->actingAs($this->admin())->from('/patients')
            ->post("/admissions/{$a->id}/medical-discharge", [
                'outcome' => 'Dead', 'medical_discharge_date' => now()->toDateString(), 'complete' => true,
            ])->assertRedirect('/patients')->assertSessionHasNoErrors();
        $this->assertSame('Mortuary', $a->fresh()->discharge_to);
    }

    public function test_medical_only_without_delay_reason_is_rejected(): void
    {
        $a = $this->admission();

        $this->actingAs($this->admin())->from('/patients')
            ->post("/admissions/{$a->id}/medical-discharge", [
                'outcome' => 'Alive', 'medical_discharge_date' => now()->toDateString(),
            ])->assertRedirect('/patients')->assertSessionHasErrors('delay_reason');
        $this->assertNull($a->fresh()->medical_discharge_date);
    }

    // ---- 7. assign targets must be active consultants -------------------------------------------

    public function test_assign_to_inactive_consultant_is_rejected(): void
    {
        $a = $this->admission();
        $inactive = $this->user(User::ROLE_CONSULTANT, ['active' => 0]);

        $this->actingAs($this->admin())->from('/admissions')
            ->post("/admissions/{$a->id}/assign", ['consultant_id' => $inactive->id])
            ->assertRedirect('/admissions')->assertSessionHasErrors('consultant_id');
        $this->assertNull($a->fresh()->consultant_id);
    }

    public function test_assign_to_non_consultant_is_rejected(): void
    {
        $a = $this->admission();
        $registrar = $this->user(User::ROLE_REGISTRAR);

        $this->actingAs($this->admin())->from('/admissions')
            ->post("/admissions/{$a->id}/assign", ['consultant_id' => $registrar->id])
            ->assertRedirect('/admissions')->assertSessionHasErrors('consultant_id');
    }

    public function test_bulk_reassign_to_inactive_consultant_is_rejected(): void
    {
        $from = $this->consultant('From');
        $inactive = $this->user(User::ROLE_CONSULTANT, ['active' => 0]);
        $this->admission(['consultant_id' => $from->id]);

        $this->actingAs($this->admin())->from('/patients')
            ->post('/admissions/reassign', ['from_consultant_id' => $from->id, 'to_consultant_id' => $inactive->id])
            ->assertRedirect('/patients')->assertSessionHasErrors('to_consultant_id');
    }

    public function test_specialty_transfer_to_inactive_consultant_is_rejected(): void
    {
        // validation rejects an inactive target BEFORE the same-day-handover gate, so no note needed
        $c = $this->consultant('Curr');
        $a = $this->admission(['consultant_id' => $c->id]);
        $spec = \App\Models\Specialty::create(['name' => 'Cardio N1', 'is_external' => false]);
        $inactive = $this->user(User::ROLE_CONSULTANT, ['active' => 0]);

        $this->actingAs($this->admin())->from('/patients')
            ->post("/admissions/{$a->id}/transfer", [
                'mode' => 'specialty', 'specialty_id' => $spec->id, 'consultant_id' => $inactive->id,
            ])->assertRedirect('/patients')->assertSessionHasErrors('consultant_id');
    }

    // ---- 8. per-consultant readmissions credit a single anchor ----------------------------------

    public function test_per_consultant_readmit_credits_a_single_most_recent_anchor(): void
    {
        // one readmission with TWO qualifying prior discharges under DIFFERENT consultants.
        // The per-consultant column must credit only the MOST-RECENT prior discharge's consultant,
        // so the per-consultant sum equals the headline distinct count (1), not 2.
        $early = $this->consultant('Dr Early');
        $recent = $this->consultant('Dr Recent');
        $p = Patient::create(['mrn' => '94000099', 'name' => 'Double Anchor']);
        // older discharge under Dr Early (2d before the readmit — within the default 3d window)
        $this->admission(['consultant_id' => $early->id, 'admit_date' => '2024-06-01',
            'discharge_date' => '2024-06-07', 'transfer_type' => 'discharge from ward', 'outcome' => 'Alive'], $p);
        // more-recent discharge under Dr Recent (1d before the readmit — also within the window).
        // Its admit (06-02) PRECEDES Dr Early's discharge, so this episode is NOT itself a readmission.
        $this->admission(['consultant_id' => $recent->id, 'admit_date' => '2024-06-02',
            'discharge_date' => '2024-06-08', 'transfer_type' => 'discharge from ward', 'outcome' => 'Alive'], $p);
        // the readmission: both priors qualify, so the headline counts it ONCE — the per-consultant
        // column must credit only the most-recent anchor (Dr Recent), summing to 1 not 2
        $this->admission(['consultant_id' => $recent->id, 'admit_date' => '2024-06-09'], $p);

        $this->actingAs($this->admin())
            ->get('/statistics?from=2024-06-01&to=2024-06-30')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('kpis.readmissions', 1)
                ->where('perConsultant', function ($rows) {
                    $rows = collect($rows);
                    $sum = $rows->sum('readmits');

                    return $sum === 1
                        && ($rows->firstWhere('name', 'Dr Recent')['readmits'] ?? null) === 1
                        && ($rows->firstWhere('name', 'Dr Early')['readmits'] ?? null) === 0;
                }));
    }

    // ---- Phase-0 Item 5: Admission::readmissionExists single source of truth --------------------

    /**
     * The unified whereExists closure must select an admission iff it is a readmission within the
     * window of a prior REAL discharge — the exact rule the board badge / registry filter / registry
     * badge all rebuild by hand today. A within-window prior discharge => true; outside => false.
     */
    public function test_readmission_exists_matches_board_badge(): void
    {
        $window = 3;

        // patient WITH a prior real discharge 2 days before the new admit (inside the window)
        $p1 = Patient::create(['mrn' => (string) random_int(10000000, 99999999), 'name' => 'Readmit In']);
        $this->admission([
            'admit_date' => now()->subDays(10)->toDateString(),
            'discharge_date' => now()->subDays(2)->toDateString(),
            'transfer_type' => 'discharge from ward',
        ], $p1);
        $within = $this->admission(['admit_date' => now()->toDateString()], $p1);

        // patient WHOSE prior discharge is well outside the window (10 days before the new admit)
        $p2 = Patient::create(['mrn' => (string) random_int(10000000, 99999999), 'name' => 'Readmit Out']);
        $this->admission([
            'admit_date' => now()->subDays(20)->toDateString(),
            'discharge_date' => now()->subDays(10)->toDateString(),
            'transfer_type' => 'discharge from ward',
        ], $p2);
        $outside = $this->admission(['admit_date' => now()->toDateString()], $p2);

        $this->assertTrue(
            Admission::whereExists(Admission::readmissionExists($window))->whereIn('id', [$within->id])->exists(),
            'an admission within the window of a prior real discharge IS a readmission'
        );
        $this->assertFalse(
            Admission::whereExists(Admission::readmissionExists($window))->whereIn('id', [$outside->id])->exists(),
            'an admission outside the window is NOT a readmission'
        );
    }
}
