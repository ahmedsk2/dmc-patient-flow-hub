<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\AdmissionDiagnosis;
use App\Models\Consultation;
use App\Models\Icd10;
use App\Models\Patient;
use App\Models\TbDiagnosis;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Final-sweep batch G2 (server-behavior slices):
 *  1. registry admissions-mode rows ship an expandable detail payload (diagnoses, attribution
 *     names, clinical-vs-physical dates, transfer label) + badge flags (TB / window-readmit /
 *     long-term / "Disch. still in"), computed page-scoped (whereIn over the 20 visible rows)
 *  3. dashboard gains 'avgLosMonth' — AVG(DATEDIFF) over non-ICU discharges in the current month
 *  4. statistics per-consultant 'activity' mode (admissions/discharges/consultations/signoffs)
 *     + physician drill-down bucketed 4-series over the selected range/interval
 *  5. registry consultations indication filter gains ind_match=and|or (or stays the default)
 */
class FinalSweepG2Test extends TestCase
{
    use RefreshDatabase;

    private function user(int $role = User::ROLE_CONSULTANT, array $extra = []): User
    {
        return User::create(array_merge([
            'username' => 'g2_'.substr(md5(uniqid('', true)), 0, 10),
            'name' => 'G2 User', 'password' => 'secret12345', 'role' => $role, 'active' => 1,
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
        ], $extra));
    }

    private function admin(): User
    {
        return $this->user(User::ROLE_ADMIN);
    }

    private function admission(array $overrides = [], ?Patient $patient = null): Admission
    {
        $p = $patient ?? Patient::create(['mrn' => (string) random_int(10000000, 99999999), 'name' => 'G2 Patient']);

        return Admission::create(array_merge([
            'patient_id' => $p->id, 'admit_date' => '2024-06-02',
            'current_location' => 'Ward', 'is_longterm' => 0, 'is_new_assignment' => 0,
        ], $overrides));
    }

    // ---- 1. registry expandable row detail ------------------------------------------------------

    public function test_registry_admission_rows_carry_detail_fields_and_badges(): void
    {
        $admitter = $this->user(User::ROLE_CONSULTANT, ['full_name' => 'Dr Admitter']);
        $discharger = $this->user(User::ROLE_CONSULTANT, ['full_name' => 'Dr Discharger']);
        Icd10::create(['code' => 'J18.9', 'name' => 'Pneumonia, unspecified organism']);
        Icd10::create(['code' => 'A15.0', 'name' => 'Tuberculosis of lung']);
        TbDiagnosis::create(['icd10_code' => 'A15.0']);

        // badge row: prior REAL discharge 1 day earlier -> window readmit; TB dx; long-term;
        // medical date set + physical discharge open -> "Disch. still in"
        $p = Patient::create(['mrn' => '80000001', 'name' => 'Badge Pt']);
        $this->admission(['admit_date' => '2024-05-20', 'discharge_date' => '2024-06-01',
            'transfer_type' => 'discharge from ward'], $p);
        $a = $this->admission([
            'admit_date' => '2024-06-02', 'medical_discharge_date' => '2024-06-05',
            'is_longterm' => 1, 'admitted_by' => $admitter->id, 'admitted_from' => 'ER',
            'delay_reason' => 'Physical',
        ], $p);
        AdmissionDiagnosis::create(['admission_id' => $a->id, 'seq' => 1, 'icd10_code' => 'J18.9']);
        AdmissionDiagnosis::create(['admission_id' => $a->id, 'seq' => 2, 'icd10_code' => 'A15.0']);

        // transfer row: closed via an intra-department (specialty) transfer
        $b = $this->admission([
            'admit_date' => '2024-06-03', 'discharge_date' => '2024-06-08',
            'discharged_by' => $discharger->id, 'discharge_to' => 'Other Facility',
            'transfer_type' => 'transfer to other speciality',
        ]);

        $this->actingAs($this->admin())
            ->get('/registry?from=2024-06-02&to=2024-06-30')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('results.total', 2)
                // newest admit first: B (06-03) then A (06-02)
                ->where('results.data.0.id', $b->id)
                ->where('results.data.0.transfer_label', 'Intra-dept transfer')
                ->where('results.data.0.discharged_by', 'Dr Discharger')
                ->where('results.data.0.admitted_by', null)
                ->where('results.data.0.discharge_to', 'Other Facility')
                ->where('results.data.0.is_tb', false)
                ->where('results.data.0.is_readmission', false)
                ->where('results.data.0.disch_still_in', false)
                ->where('results.data.1.id', $a->id)
                ->where('results.data.1.admitted_by', 'Dr Admitter')
                ->where('results.data.1.admitted_from', 'ER')
                ->where('results.data.1.medical_discharge_date', '2024-06-05')
                ->where('results.data.1.delay_reason', 'Physical')
                ->where('results.data.1.transfer_label', null)
                ->where('results.data.1.is_tb', true)
                ->where('results.data.1.is_readmission', true)
                ->where('results.data.1.is_longterm', true)
                ->where('results.data.1.disch_still_in', true)
                ->where('results.data.1.diagnoses', [
                    ['code' => 'J18.9', 'name' => 'Pneumonia, unspecified organism'],
                    ['code' => 'A15.0', 'name' => 'Tuberculosis of lung'],
                ]));
    }

    public function test_registry_transfer_labels_cover_all_three_transfer_types(): void
    {
        $map = [
            'transfer to other speciality' => 'Intra-dept transfer',
            'other transfer' => 'Out-dept transfer',
            'Transfer from ICU' => 'Back from ICU',
            'discharge from ward' => null,   // a real discharge is NOT labelled as a transfer
        ];
        foreach ($map as $type => $label) {
            $this->admission(['admit_date' => '2024-06-10',
                'discharge_date' => '2024-06-12', 'transfer_type' => $type]);
        }

        // same admit date -> ordered id DESC, i.e. reverse creation order of $map
        $expected = array_reverse(array_values($map));
        $this->actingAs($this->admin())
            ->get('/registry?from=2024-06-01&to=2024-06-30')
            ->assertInertia(function (AssertableInertia $page) use ($expected) {
                $page->where('results.total', count($expected));
                foreach ($expected as $i => $label) {
                    $page->where("results.data.{$i}.transfer_label", $label);
                }
            });
    }

    // ---- 3. dashboard month avg LOS --------------------------------------------------------------

    public function test_dashboard_avg_los_month_is_hand_computed_over_current_month_non_icu(): void
    {
        $today = Carbon::today();

        // two non-ICU discharges this month: LOS 4 and 7 -> avg 5.5
        $this->admission(['admit_date' => $today->copy()->subDays(4)->toDateString(),
            'discharge_date' => $today->toDateString(), 'current_location' => 'Ward']);
        $this->admission(['admit_date' => $today->copy()->subDays(7)->toDateString(),
            'discharge_date' => $today->toDateString(), 'current_location' => 'Ward']);
        // ICU discharge this month -> excluded
        $this->admission(['admit_date' => $today->copy()->subDays(10)->toDateString(),
            'discharge_date' => $today->toDateString(), 'current_location' => 'ICU']);
        // non-ICU discharge LAST month -> excluded
        $lastMonth = $today->copy()->startOfMonth()->subDay();
        $this->admission(['admit_date' => $lastMonth->copy()->subDays(3)->toDateString(),
            'discharge_date' => $lastMonth->toDateString(), 'current_location' => 'Ward']);

        $this->actingAs($this->admin())->get('/')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('kpis.avgLosMonth', fn ($v) => (float) $v === 5.5));
    }

    // ---- 4. per-consultant activity mode + physician time-series --------------------------------

    private function seedActivityFixture(): array
    {
        $c = $this->user(User::ROLE_CONSULTANT, ['full_name' => 'Dr Act']);
        $d = $this->user(User::ROLE_CONSULTANT, ['full_name' => 'Dr Cons Only']);

        // Dr Act: 3 admissions in range (2 Jun + 1 Jul), 2 discharges (1 Jun + 1 Jul)
        $this->admission(['consultant_id' => $c->id, 'admit_date' => '2024-06-02',
            'discharge_date' => '2024-06-05', 'transfer_type' => 'discharge from ward']);
        $this->admission(['consultant_id' => $c->id, 'admit_date' => '2024-06-10',
            'discharge_date' => '2024-07-03', 'transfer_type' => 'discharge from ward']);
        $this->admission(['consultant_id' => $c->id, 'admit_date' => '2024-07-15']);
        // 2 consultations (1 Jun + 1 Jul), 1 signoff (Jul)
        Consultation::create(['mrn' => '80000010', 'patient_name' => 'K One', 'consultant_id' => $c->id,
            'consultation_date' => '2024-06-20', 'signoff_date' => '2024-07-01', 'indication' => []]);
        Consultation::create(['mrn' => '80000011', 'patient_name' => 'K Two', 'consultant_id' => $c->id,
            'consultation_date' => '2024-07-05', 'indication' => []]);
        // Dr Cons Only: a consultation but NO admissions — must still appear in the activity rows
        Consultation::create(['mrn' => '80000012', 'patient_name' => 'K Three', 'consultant_id' => $d->id,
            'consultation_date' => '2024-06-15', 'indication' => []]);

        return [$c, $d];
    }

    public function test_per_consultant_activity_values_match_fixture(): void
    {
        $this->seedActivityFixture();

        $this->actingAs($this->admin())
            ->get('/statistics?from=2024-06-01&to=2024-07-31')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('perConsultant.0.name', 'Dr Act')
                ->where('perConsultant.0.admissions', 3)
                ->where('perConsultant.0.discharges', 2)
                ->where('perConsultant.0.consultations', 2)
                ->where('perConsultant.0.signoffs', 1)
                // consultations-only consultant still gets an activity row
                ->where('perConsultant.1.name', 'Dr Cons Only')
                ->where('perConsultant.1.admissions', 0)
                ->where('perConsultant.1.discharges', 0)
                ->where('perConsultant.1.consultations', 1)
                ->where('perConsultant.1.signoffs', 0));
    }

    public function test_physician_drilldown_series_is_bucketed_by_interval(): void
    {
        [$c] = $this->seedActivityFixture();

        $this->actingAs($this->admin())
            ->get("/statistics?from=2024-06-01&to=2024-07-31&consultant_id={$c->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('physician.series.labels', ['Jun 24', 'Jul 24'])
                ->where('physician.series.admissions', [2, 1])
                ->where('physician.series.discharges', [1, 1])
                ->where('physician.series.consultations', [1, 1])
                ->where('physician.series.signoffs', [0, 1]));
    }

    // ---- 5. consultations indication AND/OR ------------------------------------------------------

    public function test_consultation_indication_and_requires_all_ids_or_stays_superset(): void
    {
        Consultation::create(['mrn' => '80000020', 'patient_name' => 'Both Inds',
            'consultation_date' => '2024-06-01', 'indication' => [1, 2]]);
        Consultation::create(['mrn' => '80000021', 'patient_name' => 'One Ind',
            'consultation_date' => '2024-06-02', 'indication' => [1]]);
        Consultation::create(['mrn' => '80000022', 'patient_name' => 'Other Ind',
            'consultation_date' => '2024-06-03', 'indication' => [3]]);

        // OR (explicit): any selected id matches -> superset
        $this->actingAs($this->admin())
            ->get('/registry?mode=consultations&indication[]=1&indication[]=2&ind_match=or')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('results.total', 2));

        // default (no ind_match) behaves as OR
        $this->actingAs($this->admin())
            ->get('/registry?mode=consultations&indication[]=1&indication[]=2')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('results.total', 2));

        // AND: every selected id must be present
        $this->actingAs($this->admin())
            ->get('/registry?mode=consultations&indication[]=1&indication[]=2&ind_match=and')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('results.total', 1)
                ->where('results.data.0.mrn', '80000020'));
    }
}
