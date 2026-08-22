<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\AuditLog;
use App\Models\Consultation;
use App\Models\Icd10;
use App\Models\Patient;
use App\Models\Specialty;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Round-4 fix batch H1 — reverts/regressions against maintainer-confirmed legacy decisions:
 *  1. transfer stamps: location/icu-pull closing rows get discharge_to + outcome='Alive' (legacy
 *     dmc-patients.php:43,120 / dmc-patients-icu-transfer.php:41) — un-flatlines Trans-to-ICU
 *  2. outcome vocabulary is strictly Alive/Dead (LAMA/Absconded are DESTINATIONS);
 *     medical-only discharge locks outcome to Alive; the complete step asks Alive/Dead
 *  3. Modify validates only-on-change (dirty legacy records stay editable) + duplicate-MRN
 *     re-points the admission to the existing patient (legacy duplicate-MRN-is-normal shape)
 *  4. board view=longterm lists discharged long-term rows too (legacy listed ALL)
 *  5. consultations: consultant required only for INTERNAL to_service (external stored none)
 *  7. login throttle keyed by username+IP (shared hospital NAT must not lock everyone out)
 *  8. ICD-10 typeahead relevance: code-prefix, then name-prefix, then substring
 */
class Round4H1Test extends TestCase
{
    use RefreshDatabase;

    private function user(int $role = User::ROLE_CONSULTANT, array $extra = []): User
    {
        return User::create(array_merge([
            'username' => 'h1_' . substr(md5(uniqid('', true)), 0, 10),
            'name' => 'H1 User', 'password' => 'secret12345', 'role' => $role, 'active' => 1,
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
        ], $extra));
    }

    private function admin(): User
    {
        return $this->user(User::ROLE_ADMIN);
    }

    private function admission(array $overrides = [], ?Patient $patient = null): Admission
    {
        $p = $patient ?: Patient::create(['mrn' => (string) random_int(10000000, 99999999), 'name' => 'H1 Patient']);

        return Admission::create(array_merge([
            'patient_id' => $p->id, 'admit_date' => now()->subDays(3)->toDateString(),
            'current_location' => 'Ward', 'is_longterm' => 0, 'is_new_assignment' => 0,
        ], $overrides));
    }

    // ---- 1. transfer stamps (discharge_to + outcome='Alive' on the closing row) ---------------

    public function test_ward_to_icu_transfer_stamps_destination_and_alive_outcome(): void
    {
        $a = $this->admission();

        $this->actingAs($this->admin())->post("/admissions/{$a->id}/transfer", ['target' => 'ICU'])->assertRedirect();

        $a->refresh();
        $this->assertNotNull($a->discharge_date);
        $this->assertSame('Intensive Care (ICU)', $a->discharge_to, 'moving INTO ICU stamps the legacy destination (Trans-to-ICU stat keys on it)');
        $this->assertSame('Alive', $a->outcome, 'legacy stamps MORTALITY=Alive on a transfer-closed row');
    }

    public function test_icu_to_ward_transfer_stamps_ward_destination(): void
    {
        $a = $this->admission(['current_location' => 'ICU']);

        $this->actingAs($this->admin())->post("/admissions/{$a->id}/transfer", ['target' => 'Ward'])->assertRedirect();

        $a->refresh();
        $this->assertSame('Ward', $a->discharge_to);
        $this->assertSame('Alive', $a->outcome);
    }

    public function test_icu_pull_stamps_ward_destination_and_alive_outcome(): void
    {
        $a = $this->admission(['current_location' => 'ICU']);

        $this->actingAs($this->admin())->post("/admissions/{$a->id}/icu-pull")->assertRedirect();

        $a->refresh();
        $this->assertNotNull($a->discharge_date);
        $this->assertSame('Ward', $a->discharge_to, 'legacy icu-transfer sets DISTO=Ward on the closing ICU row');
        $this->assertSame('Alive', $a->outcome);
    }

    public function test_specialty_and_external_transfers_stamp_alive_outcome(): void
    {
        $spec = Specialty::create(['name' => 'Cardiology', 'is_subspecialty' => true]);
        $cardio = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $spec->id]);
        $a = $this->admission();

        $this->actingAs($this->admin())->post("/admissions/{$a->id}/transfer", [
            'mode' => 'specialty', 'specialty_id' => $spec->id, 'consultant_id' => $cardio->id,
        ])->assertRedirect();
        $a->refresh();
        $this->assertSame('Cardiology', $a->discharge_to);
        $this->assertSame('Alive', $a->outcome);

        Specialty::create(['name' => 'Psychiatry', 'is_subspecialty' => true, 'is_external' => true]);
        $b = $this->admission();
        $this->actingAs($this->admin())->post("/admissions/{$b->id}/transfer", [
            'mode' => 'external', 'service' => 'Psychiatry',
        ])->assertRedirect();
        $b->refresh();
        $this->assertSame('Psychiatry', $b->discharge_to);
        $this->assertSame('Alive', $b->outcome);
    }

    // ---- 2. outcome vocabulary: strictly Alive/Dead --------------------------------------------

    public function test_medical_only_discharge_locks_outcome_to_alive(): void
    {
        $a = $this->admission();

        // legacy phase-1 stored Alive always — outcome is no longer part of the medical-only payload
        $this->actingAs($this->admin())->post("/admissions/{$a->id}/medical-discharge", [
            'medical_discharge_date' => now()->toDateString(), 'delay_reason' => 'Physical',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $a->refresh();
        $this->assertNotNull($a->medical_discharge_date);
        $this->assertNull($a->discharge_date);
        $this->assertSame('Alive', $a->outcome, 'medical-only locks the outcome to Alive (status is re-asked at completion)');
        $this->assertNull($a->discharge_to, 'destination is asked at the COMPLETE step, not phase 1');
    }

    public function test_medical_only_ignores_a_tampered_outcome(): void
    {
        $a = $this->admission();

        $this->actingAs($this->admin())->post("/admissions/{$a->id}/medical-discharge", [
            'outcome' => 'Dead', 'medical_discharge_date' => now()->toDateString(), 'delay_reason' => 'Physical',   // medical-only needs the delay reason (N1-4)
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('Alive', $a->fresh()->outcome, 'server forces Alive when complete!=true');
    }

    public function test_one_step_complete_discharge_with_dead_works_and_forces_mortuary(): void
    {
        $a = $this->admission();

        $this->actingAs($this->admin())->post("/admissions/{$a->id}/medical-discharge", [
            'outcome' => 'Dead', 'medical_discharge_date' => now()->toDateString(),
            'discharge_to' => 'Home', 'complete' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $a->refresh();
        $this->assertNotNull($a->discharge_date);
        $this->assertSame('Dead', $a->outcome);
        $this->assertSame('Mortuary', $a->discharge_to);
    }

    public function test_one_step_complete_discharge_requires_a_status(): void
    {
        $a = $this->admission();

        $this->actingAs($this->admin())->from('/patients')->post("/admissions/{$a->id}/medical-discharge", [
            'medical_discharge_date' => now()->toDateString(), 'complete' => true,
        ])->assertRedirect('/patients')->assertSessionHasErrors('outcome');

        $this->assertNull($a->fresh()->medical_discharge_date);
    }

    public function test_lama_outcome_is_rejected_everywhere(): void
    {
        $ward = $this->admission();
        $this->actingAs($this->admin())->from('/patients')->post("/admissions/{$ward->id}/medical-discharge", [
            'outcome' => 'LAMA', 'medical_discharge_date' => now()->toDateString(), 'complete' => true,
        ])->assertRedirect('/patients')->assertSessionHasErrors('outcome');
        $this->assertNull($ward->fresh()->medical_discharge_date);

        $icu = $this->admission(['current_location' => 'ICU']);
        $this->actingAs($this->admin())->from('/patients')->post("/admissions/{$icu->id}/icu-discharge", [
            'outcome' => 'LAMA', 'discharge_date' => now()->toDateString(),
        ])->assertRedirect('/patients')->assertSessionHasErrors('outcome');
        $this->assertNull($icu->fresh()->discharge_date);

        $med = $this->admission(['medical_discharge_date' => now()->toDateString(), 'outcome' => 'Alive']);
        $this->actingAs($this->admin())->from('/patients')->post("/admissions/{$med->id}/complete-discharge", [
            'discharge_date' => now()->toDateString(), 'outcome' => 'LAMA',
        ])->assertRedirect('/patients')->assertSessionHasErrors('outcome');
        $this->assertNull($med->fresh()->discharge_date);
    }

    public function test_lama_remains_a_valid_destination(): void
    {
        $a = $this->admission(['current_location' => 'ICU']);

        $this->actingAs($this->admin())->post("/admissions/{$a->id}/icu-discharge", [
            'outcome' => 'Alive', 'discharge_date' => now()->toDateString(), 'discharge_to' => 'LAMA',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('LAMA', $a->fresh()->discharge_to, 'LAMA is a destination, not an outcome');
    }

    // ---- 3. validate-only-on-change Modify + duplicate-MRN repoint ------------------------------

    public function test_dirty_legacy_mrn_stays_editable_when_unchanged(): void
    {
        // dirty MRN format + non-vocabulary gender are the editable-dirty proof. (Age 200 is no
        // longer storable — the Phase 4 — Item 7 CHECK caps age at 150 — so a valid-but-edge age
        // is used; the point is the MRN/gender that the Modify validate-on-change leaves alone.)
        $p = Patient::create(['mrn' => 'TBA-99/x', 'name' => 'Dirty Legacy', 'age' => 95, 'gender' => 'unknown']);
        $a = $this->admission([], $p);

        // bed-only edit: untouched dirty MRN / age / gender must NOT block the save
        $this->actingAs($this->admin())->post("/admissions/{$a->id}/modify", [
            'mrn' => 'TBA-99/x', 'name' => 'Dirty Legacy', 'age' => 95, 'gender' => 'unknown',
            'bed' => 'W-77', 'admit_date' => $a->admit_date->toDateString(), 'current_location' => 'Ward',
            'diagnoses' => [],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $a->refresh();
        $this->assertSame('W-77', $a->bed);
        $this->assertSame('TBA-99/x', $a->patient->mrn);
    }

    public function test_changed_mrn_to_new_value_still_validates_regex(): void
    {
        $a = $this->admission([], Patient::create(['mrn' => '10101010', 'name' => 'Clean P']));

        $this->actingAs($this->admin())->from('/patients')->post("/admissions/{$a->id}/modify", [
            'mrn' => 'ABC-123', 'name' => 'Clean P',
            'admit_date' => $a->admit_date->toDateString(), 'current_location' => 'Ward', 'diagnoses' => [],
        ])->assertRedirect('/patients')->assertSessionHasErrors('mrn');

        $this->assertSame('10101010', $a->patient->fresh()->mrn, 'rejected MRN must not be written');

        // a clean digit MRN is accepted
        $this->actingAs($this->admin())->post("/admissions/{$a->id}/modify", [
            'mrn' => '20202020', 'name' => 'Clean P',
            'admit_date' => $a->admit_date->toDateString(), 'current_location' => 'Ward', 'diagnoses' => [],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('20202020', $a->patient->fresh()->mrn);
    }

    public function test_changing_mrn_to_an_existing_patients_mrn_repoints_the_admission(): void
    {
        $existing = Patient::create(['mrn' => '30303030', 'name' => 'Existing Owner', 'age' => 60]);
        $orig = Patient::create(['mrn' => '40404040', 'name' => 'Typo Patient', 'age' => 31]);
        $a = $this->admission([], $orig);

        $this->actingAs($this->admin())->post("/admissions/{$a->id}/modify", [
            'mrn' => '30303030',                      // duplicate of an existing patient — repoint, don't reject
            'name' => 'Typo Patient', 'age' => 31,    // unchanged vs the loaded record — must NOT clobber the target
            'bed' => 'W-5', 'admit_date' => $a->admit_date->toDateString(), 'current_location' => 'Ward',
            'diagnoses' => [],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $a->refresh();
        $this->assertSame($existing->id, (int) $a->patient_id, 'the admission is re-pointed to the existing patient');
        $this->assertSame('Existing Owner', $a->patient->name, 'unchanged demographics do not clobber the target patient');
        $this->assertSame(60, (int) $a->patient->age);
        $this->assertSame('40404040', $orig->fresh()->mrn, 'the original patient row is left intact');

        $log = AuditLog::where('action', 'patient.repoint')->where('entity_id', (string) $a->id)->first();
        $this->assertNotNull($log, 'repoint must be audited');
        $this->assertSame($orig->id, (int) ($log->details['from_patient_id'] ?? 0));
        $this->assertSame($existing->id, (int) ($log->details['to_patient_id'] ?? 0));
    }

    public function test_repoint_propagates_only_deliberately_changed_demographics(): void
    {
        $existing = Patient::create(['mrn' => '50505050', 'name' => 'Target P', 'age' => 70, 'gender' => 'Male']);
        $orig = Patient::create(['mrn' => '60606060', 'name' => 'Source P', 'age' => 30, 'gender' => 'Male']);
        $a = $this->admission([], $orig);

        $this->actingAs($this->admin())->post("/admissions/{$a->id}/modify", [
            'mrn' => '50505050',
            'name' => 'Corrected Name',   // changed vs the loaded record — propagates to the target
            'age' => 30, 'gender' => 'Male',   // unchanged — target keeps its own values
            'admit_date' => $a->admit_date->toDateString(), 'current_location' => 'Ward', 'diagnoses' => [],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $existing->refresh();
        $this->assertSame('Corrected Name', $existing->name);
        $this->assertSame(70, (int) $existing->age, 'unchanged age must not clobber the target');
    }

    // ---- 4. longterm board view includes discharged rows ----------------------------------------

    public function test_longterm_view_lists_discharged_longterm_rows(): void
    {
        $c = $this->user(User::ROLE_CONSULTANT, ['full_name' => 'Dr LT']);
        $this->admission(['consultant_id' => $c->id, 'is_longterm' => 1,
            'admit_date' => now()->subDays(45)->toDateString(),   // admit precedes discharge (Phase 4 — Item 7 CHECK)
            'discharge_date' => now()->subDays(30)->toDateString(), 'transfer_type' => 'discharge from ward', 'outcome' => 'Alive']);
        $active = $this->admission(['consultant_id' => $c->id, 'is_longterm' => 1]);

        $this->actingAs($this->admin())->get('/patients?view=longterm')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('groups.0.counts.total', 2)
                ->where('groups.0.patients', fn ($patients) => collect($patients)->contains(fn ($p) => $p['discharged'] === true)
                    && collect($patients)->contains(fn ($p) => $p['id'] === $active->id && $p['discharged'] === false)));
    }

    public function test_other_views_still_exclude_discharged_rows(): void
    {
        $c = $this->user(User::ROLE_CONSULTANT, ['full_name' => 'Dr Other']);
        $this->admission(['consultant_id' => $c->id, 'is_longterm' => 1,
            'discharge_date' => now()->toDateString(), 'transfer_type' => 'discharge from ward', 'outcome' => 'Alive']);
        $this->admission(['consultant_id' => $c->id]);

        $this->actingAs($this->admin())->get('/patients')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('groups.0.counts.total', 1));
    }

    // ---- 5. external consultations need no consultant --------------------------------------------

    private function consultPayload(array $overrides = []): array
    {
        return array_merge([
            'mrn' => '12341234', 'patient_name' => 'Consult P', 'age' => 50, 'bed' => 'W-1',
            'current_location' => 'Ward', 'consultation_date' => now()->toDateString(),
            'consultation_from' => 'Internal Medicine', 'to_service' => 'Dietary',
            'indication' => [1],
        ], $overrides);
    }

    public function test_external_consultation_stores_without_consultant(): void
    {
        Specialty::create(['name' => 'Dietary', 'is_subspecialty' => true, 'is_external' => true]);

        // W2b: MRN 12341234 matches no patients row — acknowledge the unmatched-MRN warning
        $this->actingAs($this->admin())->post('/consultations', $this->consultPayload(['unmatched_mrn_ack' => true]))
            ->assertRedirect()->assertSessionHasNoErrors();

        $c = Consultation::first();
        $this->assertNotNull($c);
        $this->assertNull($c->consultant_id, 'external to_service stores no consultant (legacy shape)');
    }

    public function test_internal_consultation_still_requires_consultant(): void
    {
        Specialty::create(['name' => 'Cardiology', 'is_subspecialty' => true, 'is_external' => false]);

        $this->actingAs($this->admin())->from('/consultations')
            ->post('/consultations', $this->consultPayload(['to_service' => 'Cardiology']))
            ->assertRedirect('/consultations')->assertSessionHasErrors('consultant_id');

        $this->assertSame(0, Consultation::count());
    }

    public function test_imported_null_consultant_consultation_is_resavable(): void
    {
        $c = Consultation::create([
            'mrn' => '99887766', 'patient_name' => 'Imported P', 'age' => 44, 'bed' => 'W-2',
            'consultation_date' => now()->subDays(10)->toDateString(), 'consultation_from' => 'IM',
            'to_service' => 'Some Outside Clinic', 'consultant_id' => null, 'indication' => [1],
        ]);

        $this->actingAs($this->admin())->put("/consultations/{$c->id}", $this->consultPayload([
            'mrn' => '99887766', 'patient_name' => 'Imported P', 'to_service' => 'Some Outside Clinic', 'bed' => 'W-3',
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $c->refresh();
        $this->assertSame('W-3', $c->bed);
        $this->assertNull($c->consultant_id);
    }

    // ---- 7. throttle keyed by username+IP ---------------------------------------------------------

    public function test_login_throttle_is_per_username_not_per_ip(): void
    {
        // 5 failures for one username exhaust ITS bucket…
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['username' => 'alice', 'password' => 'wrong']);
        }
        $this->assertSame(429, $this->post('/login', ['username' => 'alice', 'password' => 'wrong'])->getStatusCode());

        // …but a DIFFERENT username from the same IP (shared hospital NAT) is not locked out
        $status = $this->post('/login', ['username' => 'bob', 'password' => 'wrong'])->getStatusCode();
        $this->assertNotSame(429, $status, 'a different username behind the same NAT must not be throttled');
    }

    // ---- 8. ICD-10 typeahead relevance ------------------------------------------------------------

    public function test_icd10_search_ranks_code_prefix_then_name_prefix_then_substring(): void
    {
        Icd10::create(['code' => 'Z55', 'name' => 'Contains a15 in the middle']);
        Icd10::create(['code' => 'B20', 'name' => 'A15-adjacent name prefix']);
        Icd10::create(['code' => 'A15.0', 'name' => 'Tuberculosis of lung']);

        $codes = collect($this->actingAs($this->admin())->get('/api/icd10?q=A15')->assertOk()->json())
            ->pluck('code')->all();

        $this->assertSame(['A15.0', 'B20', 'Z55'], $codes, 'code-prefix first, then name-prefix, then substring');
    }
}
