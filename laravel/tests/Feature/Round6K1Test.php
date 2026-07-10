<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\AuditLog;
use App\Models\Icd10;
use App\Models\Patient;
use App\Models\Specialty;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Round-6 fix batch K1:
 *  1. external transfer to 'Intensive Care (ICU)' ALSO opens the receiving ICU episode
 *     (legacy dmc-patients.php:49-66) — the patient stays on the census
 *  4. bulk import optional column 18 'TransferType' (3 transfer literals) OVERRIDES the
 *     location-derived discharge type; invalid literal / no discharge date = invalid row
 *  6. physician drill-down topDx + transfer-type donut are NON-ICU (legacy charts.php:922/:57)
 *  7. physician drill-down readmissions ALSO require the prior discharge to be the same
 *     consultant's (legacy charts.php:1199); the headline KPI is unchanged
 *  8. statistics drill-down consultant picker includes INACTIVE consultants
 *  9. assign-to-me is open to ANY clinical role (not Observer); assignment lands on the auth user
 * (5 = MFA-never-remembered lives in MfaTest; 10 = narrowed edit gate updated in ResidualR1Test)
 */
class Round6K1Test extends TestCase
{
    use RefreshDatabase;

    private function user(int $role = User::ROLE_CONSULTANT, array $extra = []): User
    {
        return User::create(array_merge([
            'username' => 'k1_' . substr(md5(uniqid('', true)), 0, 10),
            'name' => 'K1 User', 'password' => 'secret12345', 'role' => $role, 'active' => 1,
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
        ], $extra));
    }

    private function admin(): User
    {
        return $this->user(User::ROLE_ADMIN);
    }

    private function admission(array $overrides = [], ?Patient $patient = null): Admission
    {
        $p = $patient ?? Patient::create(['mrn' => (string) random_int(10000000, 99999999), 'name' => 'K1 Patient']);

        return Admission::create(array_merge([
            'patient_id' => $p->id, 'admit_date' => now()->subDays(4)->toDateString(),
            'current_location' => 'Ward', 'is_longterm' => 0, 'is_new_assignment' => 0,
        ], $overrides));
    }

    // ---- 1. external transfer to 'Intensive Care (ICU)' keeps the patient on the census --------

    public function test_external_transfer_to_icu_opens_receiving_icu_episode(): void
    {
        $admin = $this->admin();
        Specialty::create(['name' => 'Intensive Care (ICU)', 'is_subspecialty' => true, 'is_external' => true]);
        $cons = $this->user();
        $a = $this->admission(['consultant_id' => $cons->id, 'bed' => 'W-7', 'admitted_from' => 'ER']);
        $a->diagnoses()->create(['seq' => 1, 'icd10_code' => 'J18.9']);
        $a->diagnoses()->create(['seq' => 2, 'icd10_code' => 'E11.9']);

        $this->actingAs($admin)->post("/admissions/{$a->id}/transfer", [
            'mode' => 'external', 'service' => 'Intensive Care (ICU)',
        ])->assertRedirect()->assertSessionHasNoErrors();

        // the old episode closes exactly like any other external transfer
        $a->refresh();
        $this->assertSame(now()->toDateString(), $a->discharge_date->toDateString());
        $this->assertSame('other transfer', $a->transfer_type);
        $this->assertSame('Intensive Care (ICU)', $a->discharge_to);
        $this->assertSame('Alive', $a->outcome);

        // legacy dmc-patients.php:49-66: the patient STAYS ON THE CENSUS in the receiving ICU
        // episode — same consultant, bed + diagnoses carried, ADMFROM hardcoded 'Ward',
        // assigned_on stamped today but NOT new-flagged (legacy left newassign untouched)
        $new = Admission::where('patient_id', $a->patient_id)->whereNull('discharge_date')->first();
        $this->assertNotNull($new, 'transfer to Intensive Care (ICU) must open the receiving ICU episode');
        $this->assertSame('ICU', $new->current_location);
        $this->assertSame($cons->id, (int) $new->consultant_id, 'the ICU episode keeps the SAME consultant');
        $this->assertSame('W-7', $new->bed, 'bed carried onto the ICU episode');
        $this->assertSame('Ward', $new->admitted_from, 'legacy INSERT hardcodes ADMFROM=Ward');
        $this->assertSame(now()->toDateString(), $new->admit_date->toDateString());
        $this->assertSame($admin->id, (int) $new->admitted_by);
        $this->assertFalse($new->is_new_assignment, 'legacy left newassign untouched — no New badge');
        $this->assertSame(now()->toDateString(), $new->assigned_on?->toDateString(), 'legacy stamped assigned_on=today');
        $this->assertNull($new->assigned_at, 'NOT a new assignment — assigned_at stays NULL so the 24h New badge never fires (L1-4)');
        $this->assertSame(['J18.9', 'E11.9'], $new->diagnoses()->orderBy('seq')->pluck('icd10_code')->all(), 'diagnoses carried forward');

        $log = AuditLog::where('action', 'admission.transfer_external')->where('entity_id', (string) $a->id)->latest('id')->first();
        $this->assertSame($new->id, $log->details['new_admission_id'] ?? null, 'audit must link the receiving episode');
    }

    public function test_external_transfer_to_other_service_still_opens_no_episode(): void
    {
        $admin = $this->admin();
        Specialty::create(['name' => 'Burn Unit', 'is_subspecialty' => true, 'is_external' => true]);
        $a = $this->admission();

        $this->actingAs($admin)->post("/admissions/{$a->id}/transfer", [
            'mode' => 'external', 'service' => 'Burn Unit',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(1, Admission::where('patient_id', $a->patient_id)->count(), 'only ICU gets a receiving episode');
        $log = AuditLog::where('action', 'admission.transfer_external')->where('entity_id', (string) $a->id)->latest('id')->first();
        $this->assertArrayNotHasKey('new_admission_id', $log->details ?? []);
    }

    // ---- 4. import optional column 18 TransferType ----------------------------------------------

    /** 18-column CSV line (missing trailing cells padded empty). */
    private function importRow(array $cols): string
    {
        return implode(',', array_pad($cols, 18, ''));
    }

    public function test_import_transfer_type_overrides_derived_discharge_type(): void
    {
        // Ward row WITH TransferType: the literal overrides the derived 'discharge from ward'
        $override = $this->importRow(['92000001', 'K Override', '50', 'M', 'Saudi', '2024-03-01', '2024-03-08',
            'Alive', 'Ward', '', '', '', '', '', '', '', '', 'Transfer from ICU']);
        // Ward row WITHOUT TransferType: the existing derivation stands
        $derived = $this->importRow(['92000002', 'K Derived', '51', 'F', 'Saudi', '2024-03-02', '2024-03-09',
            'Alive', 'Ward']);

        $this->actingAs($this->admin())->post('/import', ['rows' => "{$override}\n{$derived}"])->assertRedirect();

        $p1 = Patient::where('mrn', '92000001')->first();
        $this->assertSame('Transfer from ICU', Admission::where('patient_id', $p1->id)->value('transfer_type'),
            'an explicit TransferType must override the location-derived discharge type');
        $p2 = Patient::where('mrn', '92000002')->first();
        $this->assertSame('discharge from ward', Admission::where('patient_id', $p2->id)->value('transfer_type'));
    }

    public function test_import_transfer_type_accepts_all_three_literals(): void
    {
        $rows = collect([
            ['92000011', 'transfer to other speciality'],
            ['92000012', 'other transfer'],
            ['92000013', 'Transfer from ICU'],
        ])->map(fn ($r, $i) => $this->importRow([$r[0], 'K Lit', '40', 'M', 'Saudi', '2024-03-01', '2024-03-05',
            'Alive', 'Ward', '', '', '', '', '', '', '', '', $r[1]]))->implode("\n");

        $this->actingAs($this->admin())->post('/import', ['rows' => $rows])->assertRedirect();

        foreach ([['92000011', 'transfer to other speciality'], ['92000012', 'other transfer'], ['92000013', 'Transfer from ICU']] as [$mrn, $type]) {
            $p = Patient::where('mrn', $mrn)->first();
            $this->assertSame($type, Admission::where('patient_id', $p->id)->value('transfer_type'));
        }
    }

    public function test_import_rejects_invalid_transfer_type_and_transfer_type_without_discharge(): void
    {
        $badLiteral = $this->importRow(['92000021', 'K Bad', '40', 'M', 'Saudi', '2024-03-01', '2024-03-05',
            'Alive', 'Ward', '', '', '', '', '', '', '', '', 'discharge from ward']);   // a REAL-discharge string is not a transfer literal
        $noDischarge = $this->importRow(['92000022', 'K Active', '41', 'F', 'Saudi', '2024-03-02', '',
            'Alive', 'Ward', '', '', '', '', '', '', '', '', 'other transfer']);

        // preview surfaces both errors
        $this->actingAs($this->admin())->post('/import/preview', ['rows' => "{$badLiteral}\n{$noDischarge}"])
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('preview.valid', 0)
                ->where('preview.invalid', 2)
                ->where('preview.sample.0.error', fn ($e) => str_contains((string) $e, 'TransferType must be one of'))
                ->where('preview.sample.1.error', 'TransferType requires a discharge date'));

        // store skips both
        $this->actingAs($this->admin())->post('/import', ['rows' => "{$badLiteral}\n{$noDischarge}"])->assertRedirect();
        $this->assertNull(Patient::where('mrn', '92000021')->first());
        $this->assertNull(Patient::where('mrn', '92000022')->first());
    }

    // ---- 6. physician drill-down topDx + transfer donut are NON-ICU ------------------------------

    public function test_physician_topdx_and_transfer_donut_exclude_icu_episodes(): void
    {
        Icd10::create(['code' => 'J18.9', 'name' => 'Pneumonia, unspecified organism']);
        Icd10::create(['code' => 'A41.9', 'name' => 'Sepsis, unspecified organism']);
        $c = $this->user(User::ROLE_CONSULTANT, ['full_name' => 'Dr NonIcu']);

        // ward episode — counts in topDx and the 'Discharged' bucket
        $ward = $this->admission(['consultant_id' => $c->id, 'admit_date' => '2024-06-01',
            'discharge_date' => '2024-06-05', 'outcome' => 'Alive', 'transfer_type' => 'discharge from ward']);
        $ward->diagnoses()->create(['seq' => 1, 'icd10_code' => 'J18.9']);

        // ICU-located episode — EXCLUDED from both (legacy charts.php:922/:57 non-ICU filter)
        $icu = $this->admission(['consultant_id' => $c->id, 'admit_date' => '2024-06-02', 'current_location' => 'ICU',
            'discharge_date' => '2024-06-06', 'outcome' => 'Alive', 'transfer_type' => 'discharge from ICU']);
        $icu->diagnoses()->create(['seq' => 1, 'icd10_code' => 'A41.9']);

        $this->actingAs($this->admin())
            ->get("/statistics?from=2024-06-01&to=2024-06-30&consultant_id={$c->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('physician.topDx', 1)   // the ICU episode's sepsis dx is excluded
                ->where('physician.topDx.0.label', 'Pneumonia, unspecified organism')
                ->where('physician.destinations.labels', ['Discharged', 'Intra-dept transfer', 'Out-dept transfer', 'ICU discharge'])
                ->where('physician.destinations.data', [1, 0, 0, 0]));   // ICU row excluded from the donut
    }

    // ---- 7. physician readmissions require the SAME prior consultant ----------------------------

    public function test_physician_readmissions_require_same_prior_consultant(): void
    {
        $c = $this->user(User::ROLE_CONSULTANT, ['full_name' => 'Dr Curr']);
        $o = $this->user(User::ROLE_CONSULTANT, ['full_name' => 'Dr Other']);

        // P1: prior discharge under the OTHER consultant -> NOT a drill-down readmission for C
        $p1 = Patient::create(['mrn' => '93000001', 'name' => 'K Re One']);
        $this->admission(['consultant_id' => $o->id, 'admit_date' => '2024-05-20',
            'discharge_date' => '2024-06-03', 'transfer_type' => 'discharge from ward'], $p1);
        $this->admission(['consultant_id' => $c->id, 'admit_date' => '2024-06-05'], $p1);   // 2d gap

        // P2: prior discharge under C too -> counted (legacy charts.php:1199)
        $p2 = Patient::create(['mrn' => '93000002', 'name' => 'K Re Two']);
        $this->admission(['consultant_id' => $c->id, 'admit_date' => '2024-05-21',
            'discharge_date' => '2024-06-03', 'transfer_type' => 'discharge from ward'], $p2);
        $this->admission(['consultant_id' => $c->id, 'admit_date' => '2024-06-05'], $p2);   // 2d gap

        $this->actingAs($this->admin())
            ->get("/statistics?from=2024-06-01&to=2024-06-30&consultant_id={$c->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('physician.numbers.readmissions', 1)   // P2 only — P1's prior was Dr Other's
                ->where('kpis.readmissions', 2));              // the headline KPI is unchanged
    }

    // ---- 8. drill-down picker includes inactive consultants -------------------------------------

    public function test_statistics_drilldown_picker_includes_inactive_consultants(): void
    {
        $gone = $this->user(User::ROLE_CONSULTANT, ['full_name' => 'Dr Departed', 'active' => 0]);

        $this->actingAs($this->admin())->get('/statistics')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('consultants', fn ($list) => collect($list)->pluck('id')->contains($gone->id)));
    }

    // ---- 9. assign-to-me open to any clinical role -----------------------------------------------

    public function test_assign_to_me_open_to_any_clinical_role_but_not_observer(): void
    {
        foreach ([User::ROLE_REGISTRAR, User::ROLE_CONSULTANT, User::ROLE_RESIDENT] as $role) {
            $u = $this->user($role);
            $a = $this->admission();
            $this->actingAs($u)->post("/admissions/{$a->id}/assign-to-me")->assertRedirect();
            $a->refresh();
            $this->assertSame($u->id, (int) $a->consultant_id, "role {$role} must be able to self-assign (legacy Q1)");
            $this->assertTrue($a->is_new_assignment);
        }

        $obs = $this->user(User::ROLE_OBSERVER);
        $a = $this->admission();
        $this->actingAs($obs)->post("/admissions/{$a->id}/assign-to-me")->assertForbidden();
        $this->assertNull($a->fresh()->consultant_id, 'observers stay read-only');
    }
}
