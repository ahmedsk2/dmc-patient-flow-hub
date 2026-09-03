<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\AuditLog;
use App\Models\Consultation;
use App\Models\ConsultationReason;
use App\Models\Country;
use App\Models\Icd10;
use App\Models\Patient;
use App\Models\TbDiagnosis;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Round-5 fix batch J1:
 *  1. registry edit modal posts the FULL Modify payload (admit_date + current_location required)
 *  2. indication filter matches BOTH JSON value types (legacy rows store strings: ["1","3"])
 *  3. StoreAdmissionRequest gated by Can-Add (legacy add_new_patient), not just non-Observer
 *  4. NULL-typed historical discharges count as readmission anchors (legacy parity)
 *  5. every close stamps medical_discharge_date (transfers + ICU discharge), like legacy med_DISDATE
 *  6. diagnosis keyword search no longer caps the matched ICD-10 code list at 500
 *  7. D1 own-only consultant scope is EXEMPT on view=longterm / view=tb (legacy pages were unit-wide)
 *  8. Recent: NULL-typed discharges listed + consultant shipped; signoffs carry the legacy columns
 *  9. Observers can NEVER write, regardless of capability flags
 * 10. consultation EDIT is open to any clinical role (legacy modify had no ownership check)
 * 11. inline bed edit is open to any clinical role (legacy inline edits)
 * 12. per-consultant admissions/discharges + physician series are non-ICU like the headlines
 * 13. per-consultant readmissions credit the PRIOR discharge's consultant (legacy semantics)
 * 15b. New Admissions queue ships diagnosis names (expandable list like the board)
 */
class Round5J1Test extends TestCase
{
    use RefreshDatabase;

    private function user(int $role = User::ROLE_CONSULTANT, array $extra = []): User
    {
        return User::create(array_merge([
            'username' => 'j1_'.substr(md5(uniqid('', true)), 0, 10),
            'name' => 'J1 User', 'password' => 'secret12345', 'role' => $role, 'active' => 1,
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
        ], $extra));
    }

    private function admin(): User
    {
        return $this->user(User::ROLE_ADMIN);
    }

    private function admission(array $overrides = [], ?Patient $patient = null): Admission
    {
        $p = $patient ?? Patient::create(['mrn' => (string) random_int(10000000, 99999999), 'name' => 'J1 Patient']);

        return Admission::create(array_merge([
            'patient_id' => $p->id, 'admit_date' => now()->subDays(3)->toDateString(),
            'current_location' => 'Ward', 'is_longterm' => 0, 'is_new_assignment' => 0,
        ], $overrides));
    }

    // ---- 1. registry edit modal payload ---------------------------------------------------------

    public function test_registry_style_modify_payload_saves(): void
    {
        Country::firstOrCreate(['name' => 'Saudi Arabia'], ['code' => 'SA']);
        DB::table('icd10')->insert(['code' => 'J18.9', 'name' => 'Pneumonia']);   // Phase 4 — Item 5: code must exist
        $a = $this->admission(['admit_date' => '2026-06-01', 'admitted_from' => 'ER', 'current_location' => 'Ward'],
            Patient::create(['mrn' => '92000001', 'name' => 'Registry Edit', 'age' => 50, 'gender' => 'Male']));

        // the exact field set the registry edit modal now submits (incl. the previously-missing
        // admit_date / admitted_from / current_location) must save without validation errors
        $this->actingAs($this->admin())->post("/admissions/{$a->id}/modify", [
            'mrn' => '92000001', 'name' => 'Registry Edit', 'age' => 50, 'gender' => 'Male',
            'nationality' => 'Saudi Arabia', 'bed' => 'W-44', 'diagnoses' => ['J18.9'],
            'admit_date' => '2026-06-01', 'admitted_from' => 'ER', 'current_location' => 'Ward',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $a->refresh();
        $this->assertSame('W-44', $a->bed);
        $this->assertSame(['J18.9'], $a->diagnoses()->pluck('icd10_code')->all());
    }

    public function test_modify_without_admit_date_or_location_fails_validation(): void
    {
        // documents WHY the registry modal must ship these fields — without them every save 422s
        $a = $this->admission();

        $this->actingAs($this->admin())->from('/registry')->post("/admissions/{$a->id}/modify", [
            'mrn' => $a->patient->mrn, 'name' => 'J1 Patient', 'diagnoses' => [],
        ])->assertRedirect('/registry')->assertSessionHasErrors(['admit_date', 'current_location']);
    }

    // ---- 2. indication filter matches both JSON value types -------------------------------------

    public function test_string_json_indication_rows_match_the_filter(): void
    {
        $base = ['consultation_date' => '2024-06-01', 'created_at' => now(), 'updated_at' => now()];
        // legacy-imported row: indication ids stored as JSON STRINGS
        DB::table('consultations')->insert([...$base, 'mrn' => '92000010', 'patient_name' => 'Str Json', 'indication' => '["1","3"]']);
        // app-written row: ids stored as JSON INTS
        DB::table('consultations')->insert([...$base, 'mrn' => '92000011', 'patient_name' => 'Int Json', 'indication' => '[1]']);
        DB::table('consultations')->insert([...$base, 'mrn' => '92000012', 'patient_name' => 'Other', 'indication' => '[2]']);

        // OR mode: id 1 matches BOTH the string-typed and int-typed rows
        $this->actingAs($this->admin())
            ->get('/registry?mode=consultations&indication[]=1')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('results.total', 2)
                ->where('results.data', fn ($rows) => collect($rows)->pluck('mrn')->sort()->values()->all() === ['92000010', '92000011']));

        // AND mode: every id must match in EITHER type — the ["1","3"] row qualifies for 1+3
        $this->actingAs($this->admin())
            ->get('/registry?mode=consultations&indication[]=1&indication[]=3&ind_match=and')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('results.total', 1)
                ->where('results.data.0.mrn', '92000010'));
    }

    // ---- 3. Can-Add gate on new admissions -------------------------------------------------------

    public function test_consultant_without_can_add_cannot_admit(): void
    {
        $this->actingAs($this->user(User::ROLE_CONSULTANT, ['can_add' => false]))
            ->post('/admissions', [])
            ->assertForbidden();
        $this->assertDatabaseCount('admissions', 0);
    }

    public function test_can_add_consultant_passes_the_gate(): void
    {
        // an empty payload reaches VALIDATION (not 403) — the capability gate is satisfied
        $this->actingAs($this->user(User::ROLE_CONSULTANT, ['can_add' => true]))
            ->from('/admissions/create')->post('/admissions', [])
            ->assertRedirect('/admissions/create')->assertSessionHasErrors('mrn');
    }

    // ---- 4. NULL-typed prior discharges are readmission anchors ---------------------------------

    public function test_null_typed_discharge_anchors_registry_readmit_filter_and_board_badge(): void
    {
        $c = $this->user(User::ROLE_CONSULTANT, ['full_name' => 'Dr Null Anchor']);
        $p = Patient::create(['mrn' => '92000020', 'name' => 'Null Anchor Pt']);
        // historical close WITHOUT a transfer_type (legacy rows) — now a valid anchor
        $this->admission(['admit_date' => now()->subDays(10)->toDateString(),
            'discharge_date' => now()->subDays(2)->toDateString(), 'transfer_type' => null, 'outcome' => 'Alive'], $p);
        $a = $this->admission(['admit_date' => now()->subDay()->toDateString(), 'consultant_id' => $c->id], $p);

        // registry readmit72 filter finds it
        $this->actingAs($this->admin())->get('/registry?readmit72=1')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('results.total', 1)
                ->where('results.data.0.id', $a->id)
                ->where('results.data.0.is_readmission', true));

        // board badge flags it
        $this->actingAs($this->admin())->get('/patients')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('groups.0.patients.0.is_readmission', true));
    }

    // ---- 5. every close stamps medical_discharge_date -------------------------------------------

    public function test_location_transfer_and_icu_pull_stamp_medical_discharge_date(): void
    {
        $a = $this->admission();
        $this->actingAs($this->admin())->post("/admissions/{$a->id}/transfer", ['target' => 'ICU'])->assertRedirect();
        $a->refresh();
        $this->assertSame(now()->toDateString(), $a->medical_discharge_date?->toDateString(),
            'legacy stamps med_DISDATE on every close — ward<->ICU transfer included');

        $icu = $this->admission(['current_location' => 'ICU']);
        $this->actingAs($this->admin())->post("/admissions/{$icu->id}/icu-pull")->assertRedirect();
        $icu->refresh();
        $this->assertSame(now()->toDateString(), $icu->medical_discharge_date?->toDateString());
    }

    public function test_icu_discharge_stamps_medical_discharge_date_with_the_discharge_date(): void
    {
        $a = $this->admission(['current_location' => 'ICU']);
        $date = now()->subDay()->toDateString();

        $this->actingAs($this->admin())->post("/admissions/{$a->id}/icu-discharge", [
            'outcome' => 'Alive', 'discharge_date' => $date, 'discharge_to' => 'Home',   // destination required (N1-4)
        ])->assertRedirect()->assertSessionHasNoErrors();

        $a->refresh();
        $this->assertSame($date, $a->medical_discharge_date?->toDateString(),
            'single-step ICU discharge mirrors med_DISDATE = DISDATE like legacy');
    }

    // ---- 6. diagnosis keyword search uncapped ----------------------------------------------------

    public function test_diagnosis_keyword_search_is_not_capped_at_500_codes(): void
    {
        // 501 codes whose NAME matches the keyword; the patient carries the 501st
        $rows = [];
        for ($i = 1; $i <= 501; $i++) {
            $rows[] = ['code' => 'ZZ'.str_pad((string) $i, 4, '0', STR_PAD_LEFT), 'name' => "Capwide condition {$i}"];
        }
        foreach (array_chunk($rows, 250) as $chunk) {
            Icd10::insert($chunk);
        }
        $a = $this->admission([], Patient::create(['mrn' => '92000030', 'name' => 'Cap Pt']));
        $a->diagnoses()->create(['seq' => 1, 'icd10_code' => 'ZZ0501']);

        $this->actingAs($this->admin())
            ->post('/registry', ['mode' => 'diagnosis', 'keyword' => 'Capwide'])
            ->assertInertia(fn (AssertableInertia $p) => $p->where('results.total', 1)
                ->where('results.data.0.mrn', '92000030'));
    }

    // ---- 7. D1 exemption on longterm / TB views --------------------------------------------------

    public function test_consultant_sees_unit_wide_longterm_and_tb_views(): void
    {
        Icd10::create(['code' => 'A15.0', 'name' => 'Tuberculosis of lung']);
        TbDiagnosis::create(['icd10_code' => 'A15.0']);
        $me = $this->user(User::ROLE_CONSULTANT, ['full_name' => 'Dr Me']);
        $other = $this->user(User::ROLE_CONSULTANT, ['full_name' => 'Dr Other']);
        $this->admission(['consultant_id' => $me->id, 'is_longterm' => 1]);
        $this->admission(['consultant_id' => $other->id, 'is_longterm' => 1]);
        $tb = $this->admission(['consultant_id' => $other->id]);
        $tb->diagnoses()->create(['seq' => 1, 'icd10_code' => 'A15.0']);

        // legacy long-term / TB pages were UNIT-WIDE — both consultants' groups visible
        $this->actingAs($me)->get('/patients?view=longterm')
            ->assertInertia(fn (AssertableInertia $p) => $p->has('groups', 2));
        $this->actingAs($me)->get('/patients?view=tb')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->has('groups', 1)
                ->where('groups.0.id', $other->id));

        // the default board keeps the D1 own-only scope
        $this->actingAs($me)->get('/patients')
            ->assertInertia(fn (AssertableInertia $p) => $p->has('groups', 1)->where('groups.0.id', $me->id));
    }

    // ---- 8. Recent registry fixes ----------------------------------------------------------------

    public function test_recent_lists_null_typed_discharges_grouped_with_consultant(): void
    {
        $c = $this->user(User::ROLE_CONSULTANT, ['full_name' => 'Dr Recent']);
        $this->admission(['discharge_date' => now()->toDateString(), 'transfer_type' => null,
            'outcome' => 'Alive', 'consultant_id' => $c->id],
            Patient::create(['mrn' => '92000040', 'name' => 'Null Type Disch']));

        $this->actingAs($this->admin())->get('/recent')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->has('discharges', 1)
                ->where('discharges.0.mrn', '92000040')
                ->where('discharges.0.consultant', 'Dr Recent'));
    }

    public function test_recent_signoffs_carry_the_legacy_columns(): void
    {
        $c = $this->user(User::ROLE_CONSULTANT, ['full_name' => 'Dr Signer']);
        $entry = $this->user(User::ROLE_RESIDENT, ['full_name' => 'Dr Entry']);
        $r1 = ConsultationReason::create(['name' => 'Pre-op evaluation']);
        Consultation::create([
            'mrn' => '92000050', 'patient_name' => 'Signoff Pt', 'age' => 61,
            'consultation_date' => now()->subDays(4)->toDateString(), 'consultation_from' => 'Surgery',
            'to_service' => 'Internal Medicine', 'consultant_id' => $c->id, 'entered_by' => $entry->id,
            'signoff_date' => now()->toDateString(), 'indication' => [$r1->id],
        ]);

        $this->actingAs($this->admin())->get('/recent')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('signoffs.0.age', 61)
                ->where('signoffs.0.consultation_date', now()->subDays(4)->toDateString())
                ->where('signoffs.0.consultation_from', 'Surgery')
                ->where('signoffs.0.entered_by', 'Dr Entry')
                ->where('signoffs.0.reasons.0', 'Pre-op evaluation'));
    }

    // ---- 9. Observer global write lockout --------------------------------------------------------

    public function test_observer_with_all_capability_flags_cannot_write(): void
    {
        $observer = $this->user(User::ROLE_OBSERVER, [
            'can_assign' => true, 'can_add' => true, 'can_manage' => true, 'can_modify' => true,
        ]);
        $c = $this->user(User::ROLE_CONSULTANT);
        $a = $this->admission(['consultant_id' => $c->id]);
        $icu = $this->admission(['current_location' => 'ICU']);

        $this->actingAs($observer);
        $this->post('/admissions', [])->assertForbidden();
        $this->post('/admissions/shuffle')->assertForbidden();
        $this->post("/admissions/{$a->id}/assign", ['consultant_id' => $c->id])->assertForbidden();
        $this->post('/admissions/reassign', ['from_consultant_id' => $c->id, 'to_consultant_id' => $c->id])->assertForbidden();
        $this->post("/admissions/{$a->id}/modify", ['mrn' => $a->patient->mrn, 'name' => 'X',
            'admit_date' => $a->admit_date->toDateString(), 'current_location' => 'Ward', 'diagnoses' => []])->assertForbidden();
        $this->post("/admissions/{$a->id}/bed", ['bed' => 'X'])->assertForbidden();
        $this->post("/admissions/{$a->id}/medical-discharge", ['medical_discharge_date' => now()->toDateString()])->assertForbidden();
        $this->post("/admissions/{$a->id}/complete-discharge", ['discharge_date' => now()->toDateString()])->assertForbidden();
        $this->post("/admissions/{$icu->id}/icu-discharge", ['outcome' => 'Alive', 'discharge_date' => now()->toDateString()])->assertForbidden();
        $this->post("/admissions/{$a->id}/transfer", ['target' => 'ICU'])->assertForbidden();
        $this->post("/admissions/{$icu->id}/icu-pull")->assertForbidden();
        $this->post("/admissions/{$a->id}/handover", ['body' => 'observer note'])->assertForbidden();
        $this->post('/consultations', [])->assertForbidden();

        $this->assertNull($a->fresh()->discharge_date);
        $this->assertNull($a->fresh()->medical_discharge_date);
    }

    // ---- 10. consultation edit open to any clinical role -----------------------------------------

    public function test_any_clinical_role_can_edit_a_consultation(): void
    {
        $owner = $this->user(User::ROLE_CONSULTANT);
        $c = Consultation::create([
            'mrn' => '92000060', 'patient_name' => 'Edit Pt', 'age' => 30, 'bed' => 'W-1',
            'consultation_date' => now()->subDay()->toDateString(), 'consultation_from' => 'ER',
            'to_service' => 'Some Outside Clinic', 'consultant_id' => $owner->id, 'indication' => [1],
        ]);
        $payload = ['mrn' => '92000060', 'patient_name' => 'Edit Pt', 'age' => 30, 'bed' => 'W-9',
            'consultation_date' => now()->subDay()->toDateString(), 'consultation_from' => 'ER',
            'to_service' => 'Some Outside Clinic', 'indication' => [1]];

        // a NON-owner consultant without Manage may edit (legacy modify had no ownership check)…
        $this->actingAs($this->user(User::ROLE_CONSULTANT, ['can_manage' => false]))
            ->put("/consultations/{$c->id}", $payload)
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('W-9', $c->fresh()->bed);

        // …and a resident may too; an Observer may NOT
        $this->actingAs($this->user(User::ROLE_RESIDENT))
            ->put("/consultations/{$c->id}", [...$payload, 'bed' => 'W-10'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($this->user(User::ROLE_OBSERVER, ['can_manage' => true]))
            ->put("/consultations/{$c->id}", $payload)->assertForbidden();
    }

    // ---- 11. inline bed edit open to any clinical role -------------------------------------------

    public function test_any_clinical_role_can_update_the_bed(): void
    {
        $owner = $this->user(User::ROLE_CONSULTANT);
        $a = $this->admission(['consultant_id' => $owner->id, 'bed' => 'A-01']);

        // non-owner consultant + resident may inline-edit (legacy inline edits had no gate)
        $this->actingAs($this->user(User::ROLE_CONSULTANT))
            ->post("/admissions/{$a->id}/bed", ['bed' => 'B-02'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('B-02', $a->fresh()->bed);
        $this->actingAs($this->user(User::ROLE_RESIDENT))
            ->post("/admissions/{$a->id}/bed", ['bed' => 'B-03'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('B-03', $a->fresh()->bed);
        // the audit trail keeps recording the change
        $this->assertSame('B-02', AuditLog::where('action', 'admission.bed')
            ->latest('id')->first()->details['was'] ?? null);
    }

    // ---- 12. per-consultant stats are non-ICU ----------------------------------------------------

    public function test_per_consultant_admissions_and_discharges_exclude_icu(): void
    {
        $c = $this->user(User::ROLE_CONSULTANT, ['full_name' => 'Dr NonIcu']);
        // one WARD episode in range
        $this->admission(['consultant_id' => $c->id, 'admit_date' => '2024-06-02',
            'discharge_date' => '2024-06-05', 'transfer_type' => 'discharge from ward', 'outcome' => 'Alive']);
        // one ICU episode in range — must NOT count toward the per-consultant adm/dis (legacy NON_ICU)
        $this->admission(['consultant_id' => $c->id, 'admit_date' => '2024-06-03', 'current_location' => 'ICU',
            'discharge_date' => '2024-06-08', 'transfer_type' => 'discharge from ICU', 'outcome' => 'Alive']);

        $this->actingAs($this->admin())
            ->get("/statistics?from=2024-06-01&to=2024-06-30&consultant_id={$c->id}")
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('perConsultant.0.name', 'Dr NonIcu')
                ->where('perConsultant.0.admissions', 1)
                ->where('perConsultant.0.discharges', 1)
                // physician drill-down series follow the same non-ICU rule
                ->where('physician.series.admissions', [1])
                ->where('physician.series.discharges', [1]));
    }

    // ---- 13. readmission credited to the prior discharge's consultant ----------------------------

    public function test_per_consultant_readmissions_credit_the_prior_consultant(): void
    {
        $prior = $this->user(User::ROLE_CONSULTANT, ['full_name' => 'Dr Prior']);
        $next = $this->user(User::ROLE_CONSULTANT, ['full_name' => 'Dr Next']);
        $p = Patient::create(['mrn' => '92000070', 'name' => 'Attribution Pt']);
        $this->admission(['consultant_id' => $prior->id, 'admit_date' => '2024-06-01',
            'discharge_date' => '2024-06-05', 'transfer_type' => 'discharge from ward', 'outcome' => 'Alive'], $p);
        $this->admission(['consultant_id' => $next->id, 'admit_date' => '2024-06-07'], $p);   // 2d gap readmission

        $this->actingAs($this->admin())
            ->get('/statistics?from=2024-06-01&to=2024-06-30')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('perConsultant', function ($rows) {
                    $rows = collect($rows);

                    return ($rows->firstWhere('name', 'Dr Prior')['readmits'] ?? null) === 1
                        && ($rows->firstWhere('name', 'Dr Next')['readmits'] ?? null) === 0;
                }));
    }

    // ---- 15b. queue ships diagnosis names ---------------------------------------------------------

    public function test_new_admissions_queue_ships_diagnosis_names(): void
    {
        Icd10::create(['code' => 'J18.9', 'name' => 'Pneumonia, unspecified organism']);
        $a = $this->admission();   // unassigned -> queue
        $a->diagnoses()->create(['seq' => 1, 'icd10_code' => 'J18.9']);

        $this->actingAs($this->admin())->get('/admissions')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('queue.0.dx_count', 1)
                ->where('queue.0.diagnoses.0.code', 'J18.9')
                ->where('queue.0.diagnoses.0.name', 'Pneumonia, unspecified organism'));
    }
}
