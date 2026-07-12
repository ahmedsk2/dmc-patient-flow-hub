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
 * Wave B owner-approved behavior changes: complete-discharge outcome guard (#4),
 * rolling-24h "New" assignment window (B/#17), and ICU-aware bulk-import classification (#8).
 */
class WaveBTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'username' => 'wb_' . substr(md5(uniqid('', true)), 0, 8),
            'name' => 'WB Admin', 'password' => 'secret12345', 'role' => User::ROLE_ADMIN, 'active' => 1,
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
        ]);
    }

    private function admission(array $overrides = []): Admission
    {
        $p = Patient::create(['mrn' => (string) random_int(10000000, 99999999), 'name' => 'WB Patient']);

        return Admission::create(array_merge([
            'patient_id' => $p->id, 'admit_date' => now()->subDays(3)->toDateString(),
            'current_location' => 'Ward', 'is_longterm' => 0, 'is_new_assignment' => 0,
        ], $overrides));
    }

    public function test_complete_discharge_requires_prior_medical_discharge(): void
    {
        $a = $this->admission(); // active, never medically discharged
        $this->actingAs($this->admin())
            ->post("/admissions/{$a->id}/complete-discharge", ['discharge_date' => now()->toDateString()])
            ->assertRedirect();
        $a->refresh();
        $this->assertNull($a->discharge_date, 'complete-discharge must be rejected without a prior medical discharge (outcome guard)');
    }

    public function test_new_assignment_counts_by_flag_not_a_24h_window(): void
    {
        $c = User::create([
            'username' => 'wb_cons', 'name' => 'ZZ New Old', 'password' => 'secret12345',
            'role' => User::ROLE_CONSULTANT, 'active' => 1, 'on_service' => 1, 'specialty_id' => 1,
        ]);
        // "New" keys on the is_new_assignment FLAG, not a rolling assigned_at window — a flagged
        // assignment with an OLD assigned_at (e.g. imported data) is still New; an unflagged one is Old.
        $this->admission(['consultant_id' => $c->id, 'is_new_assignment' => 1, 'assigned_at' => now()->subHours(30)]); // flagged → new
        $this->admission(['consultant_id' => $c->id, 'is_new_assignment' => 0, 'assigned_at' => now()->subHours(1)]);  // not flagged → old

        $this->actingAs($this->admin())->get('/')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('consultantBoard', function ($cb) {
                $row = collect($cb)->firstWhere('name', 'ZZ New Old');
                return $row && $row['new'] === 1 && $row['old'] === 1;
            })
        );
    }

    public function test_assign_stamps_assigned_at(): void
    {
        $c = User::create([
            'username' => 'wb_c2', 'name' => 'Assign Target', 'password' => 'secret12345',
            'role' => User::ROLE_CONSULTANT, 'active' => 1,
        ]);
        $a = $this->admission();
        $this->actingAs($this->admin())->post("/admissions/{$a->id}/assign", ['consultant_id' => $c->id])->assertRedirect();
        $a->refresh();
        $this->assertNotNull($a->assigned_at);
        $this->assertTrue($a->assigned_at->greaterThanOrEqualTo(now()->subMinute()));
    }

    public function test_reverse_discharge_blocked_when_not_same_day(): void
    {
        // H2/B4: tightened from 48h to legacy SAME-DAY undo (48discharge.php)
        // Phase 4 — Item 4: reverse-discharge is step-up gated — provide a fresh window throughout
        $stepup = ['stepup.verified_at' => now()->getTimestamp()];
        $old = $this->admission(['admit_date' => '2024-01-01', 'discharge_date' => '2024-01-10',
            'outcome' => 'Alive', 'transfer_type' => 'discharge from ward']);
        $this->actingAs($this->admin())->withSession($stepup)->post("/admissions/{$old->id}/reverse-discharge")->assertRedirect();
        $this->assertNotNull($old->fresh()->discharge_date, 'months-old discharges must not be silently reopened');

        $recent = $this->admission(['discharge_date' => now()->toDateString(),
            'outcome' => 'Alive', 'transfer_type' => 'discharge from ward']);
        $this->actingAs($this->admin())->withSession($stepup)->post("/admissions/{$recent->id}/reverse-discharge")->assertRedirect();
        $this->assertNull($recent->fresh()->discharge_date, 'same-day discharges remain reversible');
    }

    public function test_transfer_restamps_medical_discharge_and_carries_bed(): void
    {
        $c = User::create(['username' => 'wb_c3', 'name' => 'Transfer Cons', 'password' => 'secret12345',
            'role' => User::ROLE_CONSULTANT, 'active' => 1]);
        $a = $this->admission(['consultant_id' => $c->id, 'bed' => 'W-12',
            'medical_discharge_date' => now()->subDay()->toDateString(), 'outcome' => 'Alive', 'delay_reason' => 'bed']);

        $this->actingAs($this->admin())->post("/admissions/{$a->id}/transfer", ['target' => 'ICU'])->assertRedirect();

        $a->refresh();
        $this->assertNotNull($a->discharge_date);
        // J1-5 (deliberate change): legacy stamps med_DISDATE on EVERY close — the transfer
        // supersedes the pending phase-1 date with the close date instead of clearing it
        $this->assertSame(now()->toDateString(), $a->medical_discharge_date?->toDateString(),
            'transfer restamps med_DISDATE with the close date (legacy stamps it on every close)');
        // H1 revert: legacy stamps MORTALITY='Alive' + the destination on every transfer-closed row
        $this->assertSame('Alive', $a->outcome, 'transfer-closed rows carry the legacy Alive stamp');
        $this->assertSame('Intensive Care (ICU)', $a->discharge_to);

        $new = Admission::where('patient_id', $a->patient_id)->whereNull('discharge_date')->first();
        $this->assertNotNull($new);
        $this->assertSame('ICU', $new->current_location);
        // J2-1 (deliberate change): legacy CARRIED the bed onto the receiving episode
        // (dmc-patients.php:57 copied BED) — was assertNull before round 5
        $this->assertSame('W-12', $new->bed, 'bed is carried across a ward<->ICU move (legacy parity)');
        $this->assertSame($c->id, (int) $new->consultant_id, 'consultant continuity preserved');
    }

    public function test_bulk_import_classifies_icu_discharge_by_location(): void
    {
        $rows = "3001001,Ward Disch,40,M,Saudi,2024-01-01,2024-01-05,Alive,Ward\n"
              . "3001002,Icu Disch,55,F,Saudi,2024-01-01,2024-01-06,Alive,ICU";
        $this->actingAs($this->admin())->post('/import', ['rows' => $rows])->assertRedirect();

        $ward = Admission::whereHas('patient', fn ($q) => $q->where('mrn', '3001001'))->first();
        $icu = Admission::whereHas('patient', fn ($q) => $q->where('mrn', '3001002'))->first();
        $this->assertSame('discharge from ward', $ward->transfer_type);
        $this->assertSame('discharge from ICU', $icu->transfer_type, 'ICU imports must classify as discharge from ICU');
    }
}
