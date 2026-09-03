<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\Consultation;
use App\Models\Icd10;
use App\Models\Patient;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Gap Wave 4b: de-identified mode-aware exports, import clinical-field extension,
 * statistics per-physician drill-down + per-consultant KPI modes + restored grid columns,
 * and registry filter options built from live data.
 */
class GapWave4bTest extends TestCase
{
    use RefreshDatabase;

    private function user(array $overrides = []): User
    {
        return User::create(array_merge([
            'username' => 'g4b_'.substr(md5(uniqid('', true)), 0, 8),
            'name' => 'G4b User', 'password' => 'secret12345', 'role' => User::ROLE_CONSULTANT, 'active' => 1,
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
        ], $overrides));
    }

    private function admin(): User
    {
        return $this->user(['role' => User::ROLE_ADMIN]);
    }

    private function admission(array $overrides = [], ?Patient $patient = null): Admission
    {
        $p = $patient ?? Patient::create(['mrn' => (string) random_int(10000000, 99999999), 'name' => 'G4b Patient']);

        return Admission::create(array_merge([
            'patient_id' => $p->id, 'admit_date' => '2024-06-01',
            'current_location' => 'Ward', 'is_longterm' => 0, 'is_new_assignment' => 0,
        ], $overrides));
    }

    // ---- 1. exports: de-identified + clinical columns + mode dispatch ------------------------

    public function test_admissions_export_is_deidentified_with_clinical_columns(): void
    {
        Icd10::create(['code' => 'J18.9', 'name' => 'Pneumonia, unspecified organism']);
        Icd10::create(['code' => 'E11.9', 'name' => 'Type 2 diabetes mellitus without complications']);
        $cons = $this->user(['full_name' => 'Dr Export Cons']);
        $p = Patient::create(['mrn' => '80000001', 'name' => 'Secret Person', 'age' => 61, 'gender' => 'Male', 'nationality' => 'Saudi']);
        $a = $this->admission([
            'admit_date' => '2024-05-01', 'discharge_date' => '2024-05-09', 'medical_discharge_date' => '2024-05-07',
            'admitted_from' => 'ER', 'discharge_to' => 'Home', 'delay_reason' => 'bed availability',
            'transfer_type' => 'discharge from ward', 'is_longterm' => 1, 'outcome' => 'Alive', 'consultant_id' => $cons->id,
        ], $p);
        $a->diagnoses()->create(['seq' => 1, 'icd10_code' => 'J18.9']);
        $a->diagnoses()->create(['seq' => 2, 'icd10_code' => 'E11.9']);

        $res = $this->actingAs($this->admin())->get('/registry/export');
        $res->assertOk();
        $csv = $res->streamedContent();
        $lines = preg_split('/\r\n|\r|\n/', trim($csv));
        $headers = str_getcsv($lines[0]);

        $this->assertNotContains('Name', $headers, 'exports must be de-identified (no patient name)');
        foreach (['MRN', 'Diagnoses', 'Admitted From', 'Clinical Discharge Date', 'Discharged To', 'Delay Reason', 'Transfer Type', 'Long-term'] as $h) {
            $this->assertContains($h, $headers, "missing export column {$h}");
        }
        $this->assertStringNotContainsStringIgnoringCase('Secret Person', $csv);
        $this->assertStringContainsString('80000001', $csv);
        // H2/C6 legacy export compat: values UPPERCASED, diagnoses (ICD-10 NAMES, seq order) joined ' || '
        $this->assertStringContainsString('PNEUMONIA, UNSPECIFIED ORGANISM || TYPE 2 DIABETES MELLITUS WITHOUT COMPLICATIONS', $csv);
        $row = str_getcsv($lines[1]);
        $byHeader = array_combine($headers, $row);
        $this->assertSame('HOME', $byHeader['Discharged To']);
        $this->assertSame('2024-05-07', $byHeader['Clinical Discharge Date']);
        $this->assertSame('BED AVAILABILITY', $byHeader['Delay Reason']);
        $this->assertSame('DISCHARGE FROM WARD', $byHeader['Transfer Type']);
        $this->assertSame('YES', $byHeader['Long-term']);
        $this->assertSame('ER', $byHeader['Admitted From']);
    }

    public function test_diagnosis_mode_export_returns_only_keyword_matched_rows(): void
    {
        Icd10::create(['code' => 'J18.9', 'name' => 'Pneumonia, unspecified organism']);
        Icd10::create(['code' => 'E11.9', 'name' => 'Type 2 diabetes mellitus without complications']);
        $match = Patient::create(['mrn' => '80000011', 'name' => 'Match Pt']);
        $other = Patient::create(['mrn' => '80000012', 'name' => 'Other Pt']);
        $this->admission([], $match)->diagnoses()->create(['seq' => 1, 'icd10_code' => 'J18.9']);
        $this->admission([], $other)->diagnoses()->create(['seq' => 1, 'icd10_code' => 'E11.9']);

        $res = $this->actingAs($this->admin())->get('/registry/export?mode=diagnosis&keyword=pneumonia');
        $res->assertOk();
        $csv = $res->streamedContent();

        $this->assertStringContainsString('80000011', $csv);
        $this->assertStringNotContainsString('80000012', $csv);
    }

    public function test_consultations_mode_export_works(): void
    {
        $cons = $this->user(['full_name' => 'Dr Consult Export']);
        Consultation::create(['mrn' => '80000021', 'patient_name' => 'Cons Pt', 'age' => 30,
            'consultation_date' => '2024-04-01', 'signoff_date' => '2024-04-02', 'indication' => [],
            'current_location' => 'Ward', 'consultation_from' => 'ER', 'to_service' => 'Cardiology',
            'consultant_id' => $cons->id]);

        $res = $this->actingAs($this->admin())->get('/registry/export?mode=consultations');
        $res->assertOk();
        $csv = $res->streamedContent();
        $headers = str_getcsv(preg_split('/\r\n|\r|\n/', trim($csv))[0]);

        $this->assertContains('To Service', $headers);
        $this->assertContains('Signoff', $headers);
        $this->assertStringContainsString('80000021', $csv);
        // H2/C6 legacy export compat: values UPPERCASED
        $this->assertStringContainsString('CARDIOLOGY', $csv);
        $this->assertStringContainsString('DR CONSULT EXPORT', $csv);
    }

    // ---- 2. import: optional trailing clinical columns ----------------------------------------

    public function test_import_13_column_row_resolves_diagnoses_and_consultant(): void
    {
        $cons = $this->user(['full_name' => 'Dr Import Target']);
        $row = '3009999,Hist Pt,60,M,Saudi,2024-01-01,2024-01-10,Alive,Ward,J18.9|E11.9,Dr Import Target,Home,2024-01-08';

        $this->actingAs($this->admin())->post('/import', ['rows' => $row])->assertRedirect();

        $p = Patient::where('mrn', '3009999')->first();
        $this->assertNotNull($p);
        $a = Admission::where('patient_id', $p->id)->first();
        $this->assertNotNull($a);
        $this->assertSame($cons->id, (int) $a->consultant_id);
        $this->assertFalse((bool) $a->is_new_assignment, 'historical rows must NOT be flagged as new assignments');
        $this->assertNull($a->assigned_at);
        $this->assertSame('Home', $a->discharge_to);
        $this->assertSame('2024-01-08', $a->medical_discharge_date->toDateString());
        $this->assertSame(['J18.9', 'E11.9'], $a->diagnoses()->orderBy('seq')->pluck('icd10_code')->all());
        $this->assertSame([1, 2], $a->diagnoses()->orderBy('seq')->pluck('seq')->all());
    }

    public function test_import_unmatched_consultant_keeps_row_valid_with_preview_warning(): void
    {
        $row = '3009998,Hist Pt,60,M,Saudi,2024-01-01,2024-01-10,Alive,Ward,J18.9,Dr Nobody,Home,2024-01-08';

        $this->actingAs($this->admin())->post('/import/preview', ['rows' => $row])
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('preview.valid', 1)
                ->where('preview.invalid', 0)
                ->where('preview.sample.0.ok', true)
                ->where('preview.sample.0.consultant_id', null)
                ->where('preview.sample.0.warning', fn ($w) => is_string($w) && str_contains($w, 'Dr Nobody')));

        $this->actingAs($this->admin())->post('/import', ['rows' => $row])->assertRedirect();
        $a = Admission::whereHas('patient', fn ($q) => $q->where('mrn', '3009998'))->first();
        $this->assertNotNull($a);
        $this->assertNull($a->consultant_id);
        $this->assertSame(['J18.9'], $a->diagnoses()->pluck('icd10_code')->all());
    }

    public function test_import_9_column_row_still_imports(): void
    {
        $row = '3009997,Old Pt,40,F,Egypt,2024-02-01,2024-02-05,Alive,Ward';

        $this->actingAs($this->admin())->post('/import', ['rows' => $row])->assertRedirect();

        $a = Admission::whereHas('patient', fn ($q) => $q->where('mrn', '3009997'))->first();
        $this->assertNotNull($a);
        $this->assertNull($a->consultant_id);
        $this->assertNull($a->discharge_to);
        $this->assertNull($a->medical_discharge_date);
        $this->assertSame(0, $a->diagnoses()->count());
    }

    // ---- 3. statistics: grid columns, per-consultant modes, physician drill-down ---------------

    /**
     * One hand-computed June-2024 fixture (range 2024-06-01..30, single month bucket):
     *   A1  Ward admit 06-02, disch 06-07, Dead,  'discharge from ward',  cons C, dx J18.9  -> wardDeath, LOS 5
     *   A2  ICU  admit 06-03, disch 06-08, Dead,  'discharge from ICU',   no cons          -> icuDeath
     *   A3  Ward admit 06-01, disch 06-05, Alive, 'other transfer' to 'Intensive Care (ICU)',
     *        cons C, dx J18.9+E11.9                                                         -> transToIcu, LOS 4
     *   A4  Ward admit 06-09 (same patient as A1, 2d after a REAL discharge), active, cons C -> readmission
     * Expected: grid adm 3 / disch 2 / icu 1 / transToIcu 1 / icuDeaths 1 / wardDeaths 1 / readmits 1.
     * Consultant C: admissions 3, discharges 2, deaths 1, avgLos (5+4)/2 = 4.5, readmissions 1.
     */
    private function seedStatsFixture(): User
    {
        Icd10::create(['code' => 'J18.9', 'name' => 'Pneumonia, unspecified organism']);
        Icd10::create(['code' => 'E11.9', 'name' => 'Type 2 diabetes mellitus without complications']);
        $c = $this->user(['full_name' => 'Dr Drill Down']);
        $p1 = Patient::create(['mrn' => '81000001', 'name' => 'S One']);
        $p2 = Patient::create(['mrn' => '81000002', 'name' => 'S Two']);
        $p3 = Patient::create(['mrn' => '81000003', 'name' => 'S Three']);

        $a1 = $this->admission(['admit_date' => '2024-06-02', 'discharge_date' => '2024-06-07', 'outcome' => 'Dead',
            'transfer_type' => 'discharge from ward', 'consultant_id' => $c->id], $p1);
        $a1->diagnoses()->create(['seq' => 1, 'icd10_code' => 'J18.9']);

        $this->admission(['admit_date' => '2024-06-03', 'discharge_date' => '2024-06-08', 'outcome' => 'Dead',
            'current_location' => 'ICU', 'transfer_type' => 'discharge from ICU'], $p2);

        $a3 = $this->admission(['admit_date' => '2024-06-01', 'discharge_date' => '2024-06-05', 'outcome' => 'Alive',
            'transfer_type' => 'other transfer', 'discharge_to' => 'Intensive Care (ICU)', 'consultant_id' => $c->id], $p3);
        $a3->diagnoses()->create(['seq' => 1, 'icd10_code' => 'J18.9']);
        $a3->diagnoses()->create(['seq' => 2, 'icd10_code' => 'E11.9']);

        $this->admission(['admit_date' => '2024-06-09', 'consultant_id' => $c->id], $p1);   // A4 readmission

        return $c;
    }

    public function test_kpi_grid_gains_icu_transfers_death_split_and_readmits(): void
    {
        $this->seedStatsFixture();

        $this->actingAs($this->admin())
            ->get('/statistics?from=2024-06-01&to=2024-06-30&interval=month')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('kpiGrid.0.admissions', 3)
                ->where('kpiGrid.0.discharges', 2)
                ->where('kpiGrid.0.icu', 1)
                ->where('kpiGrid.0.transToIcu', 1)
                ->where('kpiGrid.0.icuDeaths', 1)
                ->where('kpiGrid.0.wardDeaths', 1)
                ->where('kpiGrid.0.readmits', 1)
                // headline KPI + monthly chart series remain EXACTLY the reconciled formulas
                ->where('kpis.deaths', 2)
                ->where('monthly.deaths.0', 2));
    }

    public function test_per_consultant_metrics_cover_all_three_modes(): void
    {
        $this->seedStatsFixture();

        $this->actingAs($this->admin())
            ->get('/statistics?from=2024-06-01&to=2024-06-30')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('perConsultant.0.name', 'Dr Drill Down')
                ->where('perConsultant.0.admissions', 3)
                ->where('perConsultant.0.avgLos', 4.5)
                ->where('perConsultant.0.readmits', 1)
                ->has('consultants'));
    }

    public function test_physician_drilldown_returns_destinations_topdx_and_numbers(): void
    {
        $c = $this->seedStatsFixture();

        $this->actingAs($this->admin())
            ->get("/statistics?from=2024-06-01&to=2024-06-30&consultant_id={$c->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('physician.name', 'Dr Drill Down')
                ->where('physician.numbers.admissions', 3)
                ->where('physician.numbers.discharges', 2)
                ->where('physician.numbers.deaths', 1)
                ->where('physician.numbers.avgLos', 4.5)
                ->where('physician.numbers.readmissions', 1)
                // legacy destination buckets, fixed order
                ->where('physician.destinations.labels', ['Discharged', 'Intra-dept transfer', 'Out-dept transfer', 'ICU discharge'])
                ->where('physician.destinations.data', [1, 0, 1, 0])
                ->where('physician.topDx.0.label', 'Pneumonia, unspecified organism')
                ->where('physician.topDx.0.value', 2)
                ->where('physician.topDx.1.value', 1));
    }

    public function test_physician_prop_absent_without_selection_and_invalid_id_rejected(): void
    {
        $this->seedStatsFixture();

        $this->actingAs($this->admin())
            ->get('/statistics?from=2024-06-01&to=2024-06-30')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('physician', null));

        $this->actingAs($this->admin())
            ->get('/statistics?consultant_id=999999')
            ->assertSessionHasErrors('consultant_id');
    }

    // ---- 4. registry filters from live data ----------------------------------------------------

    public function test_registry_filters_by_discharged_to_and_builds_options_from_data(): void
    {
        $home = $this->admission(['discharge_date' => '2024-06-10', 'discharge_to' => 'Home',
            'admitted_from' => 'Walk-in', 'outcome' => 'Stable', 'delay_reason' => 'family delay']);
        $this->admission(['discharge_date' => '2024-06-11', 'discharge_to' => 'Rehab Center']);

        $this->actingAs($this->admin())
            ->get('/registry?discharged_to=Home')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('results.total', 1)
                ->where('results.data.0.mrn', $home->patient->mrn)
                // both option lists come from DISTINCT live data, not hardcoded arrays
                ->where('options.dischargedTo', fn ($v) => collect($v)->contains('Home') && collect($v)->contains('Rehab Center'))
                ->where('options.delays', fn ($v) => collect($v)->contains('family delay'))
                ->where('options.admittedFrom', fn ($v) => collect($v)->contains('Walk-in'))
                ->where('options.outcomes', fn ($v) => collect($v)->contains('Stable')));
    }

    public function test_registry_filters_by_delay_reason(): void
    {
        $delayed = $this->admission(['delay_reason' => 'bed availability']);
        $this->admission(['delay_reason' => 'transport']);
        $this->admission([]);

        $this->actingAs($this->admin())
            ->get('/registry?delay=bed availability')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('results.total', 1)
                ->where('results.data.0.mrn', $delayed->patient->mrn));
    }
}
