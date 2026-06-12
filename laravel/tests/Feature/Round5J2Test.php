<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\AuditLog;
use App\Models\Consultation;
use App\Models\Patient;
use App\Models\Setting;
use App\Models\Specialty;
use App\Models\TbDiagnosis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Round-5 fix batch J2 (LOW items):
 *  1. transfer episode columns follow legacy: ward->ICU stamps admitted_from='Ward', ICU->ward
 *     stamps 'ICU', specialty transfer keeps the ORIGINAL admitted_from and forces Ward —
 *     and the BED is CARRIED on location + specialty transfers (legacy carried it)
 *  2. dashboard trend spans 31 points (today-30 .. today inclusive, like the legacy 31-day loop)
 *  3. zero-activity consultants: dashboard activity24h lists every ON-SERVICE active consultant
 *     with zeros; Statistics by-consultant modes carry the full ACTIVE-consultant roster
 *  4. physician drill-down numbers gain transToIcu / consultations / signoffs + a second
 *     'Discharged to' donut with the legacy 6 buckets over non-ICU closed episodes
 *  5. dashboard consultation donut = [signed-off last 24h, active] (legacy dashboard/1.php);
 *     census donut titled with the TB count (kpis.tbActive)
 *  6. dashboard avgLosMonth + physician drill-down avgLos render 2 decimals
 *  7. Recent discharges + Registry results ship los_band (same settings-driven bands as the board)
 * 12. Observer page blocks: /admissions, /admissions/create, /consultations are 403 (legacy
 *     access_PICU_patients [0,2,3,4]); dashboard/patients/active-list/handovers stay readable
 * 13. Modify accepts an optional consultant_id (consultant role) — QUIET reassignment without
 *     the new-assignment flags (legacy Modify semantics), captured in the modify audit
 * 14. MFA self-disable is REMOVED (owner decision) — DELETE /mfa no longer routes
 */
class Round5J2Test extends TestCase
{
    use RefreshDatabase;

    private function user(int $role = User::ROLE_CONSULTANT, array $extra = []): User
    {
        return User::create(array_merge([
            'username' => 'j2_' . substr(md5(uniqid('', true)), 0, 10),
            'name' => 'J2 User', 'password' => 'secret12345', 'role' => $role, 'active' => 1,
        ], $extra));
    }

    private function admin(): User
    {
        return $this->user(User::ROLE_ADMIN);
    }

    private function admission(array $overrides = [], ?Patient $patient = null): Admission
    {
        $p = $patient ?? Patient::create(['mrn' => (string) random_int(10000000, 99999999), 'name' => 'J2 Patient']);

        return Admission::create(array_merge([
            'patient_id' => $p->id, 'admit_date' => now()->subDays(3)->toDateString(),
            'current_location' => 'Ward', 'is_longterm' => 0, 'is_new_assignment' => 0,
        ], $overrides));
    }

    // ---- 1. transfer episode columns (legacy admitted_from + carried bed) -----------------------

    public function test_ward_to_icu_transfer_carries_bed_and_stamps_admitted_from_ward(): void
    {
        $c = $this->user();
        $a = $this->admission(['consultant_id' => $c->id, 'bed' => 'W-12', 'admitted_from' => 'ER']);

        $this->actingAs($this->admin())->post("/admissions/{$a->id}/transfer", ['target' => 'ICU'])->assertRedirect();

        $new = Admission::where('patient_id', $a->patient_id)->whereNull('discharge_date')->first();
        $this->assertNotNull($new);
        $this->assertSame('ICU', $new->current_location);
        $this->assertSame('Ward', $new->admitted_from, 'legacy ward->ICU INSERT stamps ADMFROM=Ward (dmc-patients.php:57)');
        $this->assertSame('W-12', $new->bed, 'legacy carried BED across the move');
    }

    public function test_icu_to_ward_transfer_carries_bed_and_stamps_admitted_from_icu(): void
    {
        $c = $this->user();
        $a = $this->admission(['consultant_id' => $c->id, 'current_location' => 'ICU', 'bed' => 'ICU-3', 'admitted_from' => 'Ward']);

        $this->actingAs($this->admin())->post("/admissions/{$a->id}/transfer", ['target' => 'Ward'])->assertRedirect();

        $new = Admission::where('patient_id', $a->patient_id)->whereNull('discharge_date')->first();
        $this->assertNotNull($new);
        $this->assertSame('Ward', $new->current_location);
        $this->assertSame('ICU', $new->admitted_from, 'legacy ICU->Ward episodes carry ADMFROM=ICU');
        $this->assertSame('ICU-3', $new->bed, 'legacy carried BED across the move');
    }

    public function test_specialty_transfer_keeps_original_admitted_from_forces_ward_and_carries_bed(): void
    {
        $spec = Specialty::create(['name' => 'Cardiology', 'is_subspecialty' => true]);
        $cardio = $this->user(User::ROLE_CONSULTANT, ['specialty_id' => $spec->id]);
        // unassigned ICU episode: no handover gate, and the forced-Ward rule is visible
        $a = $this->admission(['consultant_id' => null, 'current_location' => 'ICU', 'bed' => 'ICU-7', 'admitted_from' => 'ER']);

        $this->actingAs($this->admin())->post("/admissions/{$a->id}/transfer", [
            'mode' => 'specialty', 'specialty_id' => $spec->id, 'consultant_id' => $cardio->id,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $new = Admission::where('patient_id', $a->patient_id)->whereNull('discharge_date')->first();
        $this->assertNotNull($new);
        $this->assertSame('ER', $new->admitted_from, 'legacy specialty INSERT copies the ORIGINAL ADMFROM (dmc-patients.php:110)');
        $this->assertSame('Ward', $new->current_location, 'legacy specialty INSERT hardcodes current_location=Ward');
        $this->assertSame('ICU-7', $new->bed, 'legacy carried BED onto the receiving episode');
    }

    public function test_icu_pull_still_opens_unassigned_ward_episode_without_bed(): void
    {
        // icuPull is NOT a bed-carrying transfer — legacy icu-transfer.php INSERT had no BED column
        $a = $this->admission(['current_location' => 'ICU', 'bed' => 'ICU-2', 'admitted_from' => 'ER']);

        $this->actingAs($this->admin())->post("/admissions/{$a->id}/icu-pull")->assertRedirect();

        $new = Admission::where('patient_id', $a->patient_id)->whereNull('discharge_date')->first();
        $this->assertSame('ICU', $new->admitted_from);
        $this->assertNull($new->bed);
    }

    // ---- 2. dashboard trend spans 31 days ---------------------------------------------------------

    public function test_dashboard_trend_has_31_inclusive_points(): void
    {
        $this->actingAs($this->admin())->get('/')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->count('trend.labels', 31)
                ->where('trend.labels.0', now()->subDays(30)->toDateString())
                ->where('trend.labels.30', now()->toDateString()));
    }

    // ---- 3. zero-activity consultants -------------------------------------------------------------

    public function test_activity24h_includes_on_service_consultants_with_zeros(): void
    {
        $idleOn = $this->user(User::ROLE_CONSULTANT, ['full_name' => 'Dr Idle OnService', 'on_service' => 1]);
        $idleOff = $this->user(User::ROLE_CONSULTANT, ['full_name' => 'Dr Idle OffService', 'on_service' => 0]);
        $busy = $this->user(User::ROLE_CONSULTANT, ['full_name' => 'Dr Busy', 'on_service' => 1]);
        $this->admission(['consultant_id' => $busy->id, 'admit_date' => now()->toDateString()]);

        $this->actingAs($this->admin())->get('/')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('activity24h', function ($rows) {
                    $rows = collect($rows);
                    $idle = $rows->firstWhere('name', 'Dr Idle OnService');

                    return $idle !== null && (int) $idle['admissions'] === 0 && (int) $idle['discharges'] === 0
                        && $rows->firstWhere('name', 'Dr Busy') !== null
                        && $rows->firstWhere('name', 'Dr Idle OffService') === null;
                }));
    }

    public function test_statistics_by_consultant_includes_active_roster_with_zeros(): void
    {
        $zero = $this->user(User::ROLE_CONSULTANT, ['full_name' => 'Dr Zero Roster']);
        $gone = $this->user(User::ROLE_CONSULTANT, ['full_name' => 'Dr Inactive Gone', 'active' => 0]);
        $busy = $this->user(User::ROLE_CONSULTANT, ['full_name' => 'Dr Roster Busy']);
        $this->admission(['consultant_id' => $busy->id, 'admit_date' => '2024-06-02']);

        $this->actingAs($this->admin())->get('/statistics?from=2024-06-01&to=2024-06-30')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('perConsultant', function ($rows) {
                    $rows = collect($rows);
                    $zero = $rows->firstWhere('name', 'Dr Zero Roster');

                    return $zero !== null && (int) $zero['admissions'] === 0 && (int) $zero['signoffs'] === 0
                        && $rows->firstWhere('name', 'Dr Roster Busy') !== null
                        && $rows->firstWhere('name', 'Dr Inactive Gone') === null;
                }));
    }

    // ---- 4. physician drill-down extras ------------------------------------------------------------

    public function test_physician_drilldown_gains_trans_to_icu_consults_signoffs_and_discharged_to_donut(): void
    {
        $c = $this->user(User::ROLE_CONSULTANT, ['full_name' => 'Dr Extras']);
        // ward episode discharged Home (real discharge)
        $this->admission(['consultant_id' => $c->id, 'admit_date' => '2024-06-01',
            'discharge_date' => '2024-06-05', 'transfer_type' => 'discharge from ward',
            'discharge_to' => 'Home', 'outcome' => 'Alive']);
        // ward episode closed by a transfer to ICU — counts transToIcu + the 'Transfer' bucket
        $this->admission(['consultant_id' => $c->id, 'admit_date' => '2024-06-02',
            'discharge_date' => '2024-06-06', 'transfer_type' => 'other transfer',
            'discharge_to' => 'Intensive Care (ICU)', 'outcome' => 'Alive']);
        // one consultation in range, one of an earlier batch signed off in range
        Consultation::create(['mrn' => '93000001', 'patient_name' => 'Cx One', 'consultant_id' => $c->id,
            'consultation_date' => '2024-06-10', 'indication' => []]);
        Consultation::create(['mrn' => '93000002', 'patient_name' => 'Cx Two', 'consultant_id' => $c->id,
            'consultation_date' => '2024-05-20', 'signoff_date' => '2024-06-12', 'indication' => []]);

        $this->actingAs($this->admin())
            ->get("/statistics?from=2024-06-01&to=2024-06-30&consultant_id={$c->id}")
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('physician.numbers.transToIcu', 1)
                ->where('physician.numbers.consultations', 1)
                ->where('physician.numbers.signoffs', 1)
                // legacy charts.php 6-bucket 'Discharged to' donut, fixed order
                ->where('physician.dischargedTo.labels', ['Home', 'Other Facility', 'LAMA', 'Absconded', 'Mortuary', 'Transfer'])
                ->where('physician.dischargedTo.data', [1, 0, 0, 0, 0, 1]));
    }

    // ---- 5. dashboard consult donut + census TB title ---------------------------------------------

    public function test_dashboard_consult_donut_and_active_tb_count(): void
    {
        \App\Models\Icd10::create(['code' => 'A15.0', 'name' => 'Tuberculosis of lung']);
        TbDiagnosis::create(['icd10_code' => 'A15.0']);

        Consultation::create(['mrn' => '93000010', 'patient_name' => 'Open Cx',
            'consultation_date' => now()->subDays(2)->toDateString(), 'indication' => []]);
        Consultation::create(['mrn' => '93000011', 'patient_name' => 'Fresh Signoff',
            'consultation_date' => now()->subDays(5)->toDateString(),
            'signoff_date' => now()->toDateString(), 'indication' => []]);
        Consultation::create(['mrn' => '93000012', 'patient_name' => 'Old Signoff',
            'consultation_date' => now()->subDays(20)->toDateString(),
            'signoff_date' => now()->subDays(10)->toDateString(), 'indication' => []]);

        $tb = $this->admission();
        $tb->diagnoses()->create(['seq' => 1, 'icd10_code' => 'A15.0']);
        $this->admission();   // active non-TB

        $this->actingAs($this->admin())->get('/')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('consultDonut.signed24h', 1)   // legacy: signoff_date + 1 day >= today
                ->where('consultDonut.active', 1)
                ->where('kpis.tbActive', 1)
                ->where('kpis.census', 2));
    }

    // ---- 6. two-decimal LOS ------------------------------------------------------------------------

    public function test_dashboard_avg_los_month_renders_two_decimals(): void
    {
        $today = now();
        foreach ([1, 1, 2] as $los) {   // avg 4/3 = 1.333... -> 1.33 at 2dp (was 1.3)
            $this->admission(['admit_date' => $today->copy()->subDays($los)->toDateString(),
                'discharge_date' => $today->toDateString(), 'transfer_type' => 'discharge from ward', 'outcome' => 'Alive']);
        }

        $this->actingAs($this->admin())->get('/')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('kpis.avgLosMonth', fn ($v) => (float) $v === 1.33));
    }

    public function test_physician_drilldown_avg_los_renders_two_decimals(): void
    {
        $c = $this->user(User::ROLE_CONSULTANT, ['full_name' => 'Dr TwoDp']);
        foreach ([['2024-06-01', '2024-06-02'], ['2024-06-01', '2024-06-03'], ['2024-06-01', '2024-06-03']] as [$in, $out]) {
            $this->admission(['consultant_id' => $c->id, 'admit_date' => $in, 'discharge_date' => $out,
                'transfer_type' => 'discharge from ward', 'outcome' => 'Alive']);
        }

        // LOS 1 + 2 + 2 = 5/3 = 1.666... -> 1.67 at 2dp (was 1.7)
        $this->actingAs($this->admin())
            ->get("/statistics?from=2024-06-01&to=2024-06-30&consultant_id={$c->id}")
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('physician.numbers.avgLos', fn ($v) => (float) $v === 1.67));
    }

    // ---- 7. los_band on Recent + Registry ----------------------------------------------------------

    public function test_recent_and_registry_ship_settings_driven_los_band(): void
    {
        Setting::current()->update(['short_los' => 5, 'long_los' => 11]);

        // LOS 2 (short), 7 (mid), 20 (long) — all discharged today so Recent lists them
        foreach ([2, 7, 20] as $los) {
            $this->admission(['admit_date' => now()->subDays($los)->toDateString(),
                'discharge_date' => now()->toDateString(), 'transfer_type' => 'discharge from ward', 'outcome' => 'Alive']);
        }

        $this->actingAs($this->admin())->get('/recent')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('discharges', fn ($rows) => collect($rows)->pluck('los_band')->sort()->values()->all()
                    === ['long', 'mid', 'short']));

        $this->actingAs($this->admin())->get('/registry?discharged=1')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('results.data', fn ($rows) => collect($rows)->pluck('los_band')->sort()->values()->all()
                    === ['long', 'mid', 'short']));
    }

    // ---- 12. Observer page blocks ------------------------------------------------------------------

    public function test_observer_blocked_from_admissions_and_consultations_pages(): void
    {
        $observer = $this->user(User::ROLE_OBSERVER);

        $this->actingAs($observer)->get('/admissions')->assertForbidden();
        $this->actingAs($observer)->get('/admissions/create')->assertForbidden();
        $this->actingAs($observer)->get('/consultations')->assertForbidden();
    }

    public function test_observer_keeps_dashboard_board_census_and_handovers(): void
    {
        $observer = $this->user(User::ROLE_OBSERVER);

        $this->actingAs($observer)->get('/')->assertOk();
        $this->actingAs($observer)->get('/patients')->assertOk();
        $this->actingAs($observer)->get('/active-list')->assertOk();
        $this->actingAs($observer)->get('/handovers')->assertOk();
    }

    public function test_observer_cannot_sign_off_even_with_manage_flag(): void
    {
        // J1-9 global read-only guarantee — closing the page must not leave the POST open
        $c = Consultation::create(['mrn' => '93000020', 'patient_name' => 'Obs Signoff',
            'consultation_date' => now()->subDay()->toDateString(), 'indication' => []]);

        $this->actingAs($this->user(User::ROLE_OBSERVER, ['can_manage' => true]))
            ->post("/consultations/{$c->id}/signoff")->assertForbidden();
        $this->assertNull($c->fresh()->signoff_date);
    }

    // ---- 13. Modify gains an optional quiet consultant change -------------------------------------

    private function modifyPayload(Admission $a, array $extra = []): array
    {
        return array_merge([
            'mrn' => $a->patient->mrn, 'name' => $a->patient->name,
            'admit_date' => $a->admit_date->toDateString(), 'current_location' => $a->current_location,
            'diagnoses' => [],
        ], $extra);
    }

    public function test_modify_quietly_reassigns_consultant_without_new_flags(): void
    {
        $from = $this->user(User::ROLE_CONSULTANT, ['full_name' => 'Dr From']);
        $to = $this->user(User::ROLE_CONSULTANT, ['full_name' => 'Dr To']);
        $a = $this->admission(['consultant_id' => $from->id]);

        $this->actingAs($this->admin())
            ->post("/admissions/{$a->id}/modify", $this->modifyPayload($a, ['consultant_id' => $to->id]))
            ->assertRedirect()->assertSessionHasNoErrors();

        $a->refresh();
        $this->assertSame($to->id, (int) $a->consultant_id);
        // QUIET legacy Modify semantics: no New badge, no assignment stamps
        $this->assertFalse($a->is_new_assignment);
        $this->assertNull($a->assigned_at);
        // audited inside the modify audit
        $audit = AuditLog::where('action', 'patient.modify')->where('entity_id', (string) $a->id)->latest('id')->first();
        $this->assertSame($to->id, (int) ($audit->details['consultant_id'] ?? 0));
        $this->assertSame($from->id, (int) ($audit->details['consultant_was'] ?? 0));
    }

    public function test_modify_without_consultant_id_leaves_the_assignment_untouched(): void
    {
        $c = $this->user();
        $a = $this->admission(['consultant_id' => $c->id]);

        $this->actingAs($this->admin())
            ->post("/admissions/{$a->id}/modify", $this->modifyPayload($a, ['bed' => 'W-3']))
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame($c->id, (int) $a->fresh()->consultant_id);
    }

    public function test_modify_rejects_a_non_consultant_consultant_id(): void
    {
        $resident = $this->user(User::ROLE_RESIDENT);
        $a = $this->admission();

        $this->actingAs($this->admin())
            ->post("/admissions/{$a->id}/modify", $this->modifyPayload($a, ['consultant_id' => $resident->id]))
            ->assertSessionHasErrors('consultant_id');
        $this->assertNull($a->fresh()->consultant_id);
    }

    public function test_admission_edit_json_ships_consultant_id_for_the_prefill(): void
    {
        $c = $this->user();
        $a = $this->admission(['consultant_id' => $c->id]);

        $this->actingAs($this->admin())->get("/admissions/{$a->id}/edit")
            ->assertOk()->assertJson(['consultant_id' => $c->id]);
    }

    // ---- 14. MFA self-disable removed --------------------------------------------------------------

    public function test_mfa_self_disable_route_is_gone(): void
    {
        // owner decision: MFA can only be reset by an admin (Control panel) — no self-disable
        $this->actingAs($this->user())->delete('/mfa')->assertNotFound();
        $this->assertFalse(method_exists(\App\Http\Controllers\MfaController::class, 'disable'));
    }
}
