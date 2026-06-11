<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\AuditLog;
use App\Models\Consultation;
use App\Models\Patient;
use App\Models\Specialty;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Final-sweep batch G1 (server-behavior slices):
 *  1. complete-discharge accepts an optional outcome/destination override (audited with outcome_was)
 *  2. numeric legacy consultation to_service values resolve to specialty names (one-time data-fix
 *     migration for already-imported rows; import-time resolution covers future imports)
 *  6. reverse-discharge also clears discharge_to (legacy nulled DISTO) — asserted in ClinicalFlowTest
 *  8. bulk import gains optional trailing AdmittedFrom / Bed / DelayReason / LongTerm columns
 *  9. forgot-password accepts a username OR an email — same generic response (no enumeration)
 */
class FinalSweepG1Test extends TestCase
{
    use RefreshDatabase;

    private function user(int $role = User::ROLE_CONSULTANT, array $extra = []): User
    {
        return User::create(array_merge([
            'username' => 'g1_' . substr(md5(uniqid('', true)), 0, 10),
            'name' => 'G1 User', 'password' => 'secret12345', 'role' => $role, 'active' => 1,
        ], $extra));
    }

    private function admin(): User
    {
        return $this->user(User::ROLE_ADMIN);
    }

    private function admission(array $overrides = [], ?Patient $patient = null): Admission
    {
        $p = $patient ?? Patient::create(['mrn' => (string) random_int(10000000, 99999999), 'name' => 'G1 Patient']);

        return Admission::create(array_merge([
            'patient_id' => $p->id, 'admit_date' => now()->subDays(4)->toDateString(),
            'current_location' => 'Ward', 'is_longterm' => 0, 'is_new_assignment' => 0,
        ], $overrides));
    }

    /** Phase-1-complete admission (medically discharged Alive → Home, still in a bed). */
    private function medicallyDischarged(): Admission
    {
        return $this->admission([
            'medical_discharge_date' => now()->subDay()->toDateString(),
            'outcome' => 'Alive', 'discharge_to' => 'Home', 'delay_reason' => 'Physical',
        ]);
    }

    // ---- 1. complete-discharge outcome/destination override -----------------------------------

    public function test_complete_discharge_override_changes_outcome_and_audits_old_value(): void
    {
        // H1 revert: outcome vocabulary is strictly Alive/Dead — the override here flips
        // the phase-1 Alive to Dead (the only legal change) and must audit the old value
        $a = $this->medicallyDischarged();

        $this->actingAs($this->admin())->post("/admissions/{$a->id}/complete-discharge", [
            'discharge_date' => now()->toDateString(),
            'outcome' => 'Dead',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $a->refresh();
        $this->assertNotNull($a->discharge_date);
        $this->assertSame('Dead', $a->outcome, 'override must replace the phase-1 outcome');

        $log = AuditLog::where('action', 'admission.complete_discharge')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame('Alive', $log->details['outcome_was'] ?? null, 'audit must capture the replaced outcome');

        // a destination-only override still works (no outcome change → no outcome_was)
        $b = $this->medicallyDischarged();
        $this->actingAs($this->admin())->post("/admissions/{$b->id}/complete-discharge", [
            'discharge_date' => now()->toDateString(), 'discharge_to' => 'Other Facility',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $b->refresh();
        $this->assertSame('Alive', $b->outcome);
        $this->assertSame('Other Facility', $b->discharge_to, 'destination override must replace the phase-1 destination');
    }

    public function test_complete_discharge_override_dead_forces_mortuary(): void
    {
        $a = $this->medicallyDischarged();

        $this->actingAs($this->admin())->post("/admissions/{$a->id}/complete-discharge", [
            'discharge_date' => now()->toDateString(),
            'outcome' => 'Dead', 'discharge_to' => 'Home',   // destination contradicts the death — server corrects
        ])->assertRedirect();

        $a->refresh();
        $this->assertSame('Dead', $a->outcome);
        $this->assertSame('Mortuary', $a->discharge_to, 'a death can only go to the mortuary');
    }

    public function test_complete_discharge_without_override_keeps_phase1_values(): void
    {
        $a = $this->medicallyDischarged();

        $this->actingAs($this->admin())->post("/admissions/{$a->id}/complete-discharge", [
            'discharge_date' => now()->toDateString(),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $a->refresh();
        $this->assertNotNull($a->discharge_date);
        $this->assertSame('Alive', $a->outcome, 'phase-1 outcome must survive an override-less close');
        $this->assertSame('Home', $a->discharge_to);

        $log = AuditLog::where('action', 'admission.complete_discharge')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertArrayNotHasKey('outcome_was', $log->details ?? [], 'no outcome change → no outcome_was');
    }

    public function test_complete_discharge_rejects_invalid_override_values(): void
    {
        $a = $this->medicallyDischarged();

        $this->actingAs($this->admin())->post("/admissions/{$a->id}/complete-discharge", [
            'discharge_date' => now()->toDateString(), 'outcome' => 'Vanished',
        ])->assertSessionHasErrors('outcome');

        $this->assertNull($a->fresh()->discharge_date);
    }

    public function test_board_payload_ships_current_outcome_and_destination(): void
    {
        $c = $this->user(User::ROLE_CONSULTANT, ['full_name' => 'Dr Board G1']);
        $this->admission([
            'consultant_id' => $c->id,
            'medical_discharge_date' => now()->subDay()->toDateString(),
            'outcome' => 'Alive', 'discharge_to' => 'Home',
        ]);

        $this->actingAs($this->admin())->get('/patients')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('groups.0.patients.0.outcome', 'Alive')
                ->where('groups.0.patients.0.discharge_to', 'Home'));
    }

    // ---- 2. numeric to_service resolution (one-time data-fix migration) ------------------------

    public function test_migration_resolves_numeric_to_service_and_leaves_text_untouched(): void
    {
        $internal = Specialty::create(['name' => 'G1 Cardiology', 'is_subspecialty' => true, 'is_external' => false]);
        $external = Specialty::create(['name' => 'G1 Physio', 'is_subspecialty' => true, 'is_external' => true]);

        $numeric = Consultation::create(['mrn' => '90000001', 'patient_name' => 'Num Pt',
            'consultation_date' => '2024-05-01', 'to_service' => (string) $internal->id]);
        $text = Consultation::create(['mrn' => '90000002', 'patient_name' => 'Text Pt',
            'consultation_date' => '2024-05-02', 'to_service' => 'Neurology']);
        // external ids were NOT stable across the legacy import (name-deduped insert) — a numeric
        // value matching an external row must stay untouched rather than resolve to a wrong name
        $extNumeric = Consultation::create(['mrn' => '90000003', 'patient_name' => 'Ext Pt',
            'consultation_date' => '2024-05-03', 'to_service' => (string) $external->id]);
        $unmatched = Consultation::create(['mrn' => '90000004', 'patient_name' => 'Lost Pt',
            'consultation_date' => '2024-05-04', 'to_service' => '987654']);

        $migration = require base_path('database/migrations/2026_06_11_000002_resolve_numeric_consultation_to_service.php');
        $migration->up();

        $this->assertSame('G1 Cardiology', $numeric->fresh()->to_service);
        $this->assertSame('Neurology', $text->fresh()->to_service);
        $this->assertSame((string) $external->id, $extNumeric->fresh()->to_service);
        $this->assertSame('987654', $unmatched->fresh()->to_service);
    }

    // ---- 8. import: optional trailing AdmittedFrom / Bed / DelayReason / LongTerm ---------------

    public function test_import_17_column_row_imports_all_fields(): void
    {
        $cons = $this->user(User::ROLE_CONSULTANT, ['full_name' => 'Dr G1 Import']);
        $row = '3010001,Hist Pt,60,M,Saudi,2024-01-01,2024-01-10,Alive,Ward,J18.9,Dr G1 Import,Home,2024-01-08,OPD,W-07,Physical,1';

        $this->actingAs($this->admin())->post('/import', ['rows' => $row])->assertRedirect();

        $a = Admission::whereHas('patient', fn ($q) => $q->where('mrn', '3010001'))->first();
        $this->assertNotNull($a);
        // 13-column fields still land
        $this->assertSame($cons->id, (int) $a->consultant_id);
        $this->assertSame('Home', $a->discharge_to);
        $this->assertSame('2024-01-08', $a->medical_discharge_date->toDateString());
        // new trailing columns
        $this->assertSame('OPD', $a->admitted_from);
        $this->assertSame('W-07', $a->bed);
        $this->assertSame('Physical', $a->delay_reason);
        $this->assertTrue((bool) $a->is_longterm);
    }

    public function test_import_longterm_accepts_yes_and_shorter_rows_stay_valid(): void
    {
        $rows = "3010002,LT Pt,50,F,Egypt,2024-02-01,,,Ward,,,,,ER,B-2,,yes\n"
              . '3010003,Short Pt,40,F,Egypt,2024-02-01,2024-02-05,Alive,Ward';

        $this->actingAs($this->admin())->post('/import', ['rows' => $rows])->assertRedirect();

        $lt = Admission::whereHas('patient', fn ($q) => $q->where('mrn', '3010002'))->first();
        $this->assertNotNull($lt);
        $this->assertTrue((bool) $lt->is_longterm);
        $this->assertSame('ER', $lt->admitted_from);
        $this->assertSame('B-2', $lt->bed);
        $this->assertNull($lt->delay_reason);

        $short = Admission::whereHas('patient', fn ($q) => $q->where('mrn', '3010003'))->first();
        $this->assertNotNull($short, 'old 9-column rows must remain valid');
        $this->assertFalse((bool) $short->is_longterm);
        $this->assertNull($short->admitted_from);
        $this->assertNull($short->bed);
    }

    // ---- 9. forgot-password by username (no enumeration) ----------------------------------------

    public function test_reset_link_sent_when_username_is_submitted(): void
    {
        Notification::fake();
        $user = $this->user(User::ROLE_CONSULTANT, ['email' => 'g1.reset@example.test']);

        $this->post('/forgot-password', ['email' => $user->username])
            ->assertRedirect()->assertSessionHas('status');

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_unknown_username_returns_the_same_generic_status(): void
    {
        Notification::fake();

        $known = $this->user(User::ROLE_CONSULTANT, ['email' => 'g1.known@example.test']);
        $knownStatus = $this->post('/forgot-password', ['email' => $known->username])
            ->assertRedirect()->getSession()->get('status');

        $unknownStatus = $this->post('/forgot-password', ['email' => 'no_such_user_g1'])
            ->assertRedirect()->getSession()->get('status');

        $this->assertSame($knownStatus, $unknownStatus, 'responses must not reveal whether the account exists');
        Notification::assertSentToTimes($known, ResetPassword::class, 1);
    }

    public function test_username_without_email_gets_generic_status_and_no_mail(): void
    {
        Notification::fake();
        $user = $this->user(User::ROLE_CONSULTANT);   // no email on file

        $this->post('/forgot-password', ['email' => $user->username])
            ->assertRedirect()->assertSessionHas('status');

        Notification::assertNothingSent();
    }

    public function test_email_form_still_works(): void
    {
        Notification::fake();
        $user = $this->user(User::ROLE_CONSULTANT, ['email' => 'g1.mail@example.test']);

        $this->post('/forgot-password', ['email' => $user->email])->assertRedirect();

        Notification::assertSentTo($user, ResetPassword::class);
    }
}
