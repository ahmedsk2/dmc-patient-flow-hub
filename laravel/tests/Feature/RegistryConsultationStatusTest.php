<?php

namespace Tests\Feature;

use App\Models\Consultation;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Defect (C) — walkthrough 2026-09-23: RegistryController::consultationResults() never sent the
 * `status` column, so Registry/Index.vue (which only ever branched on the presence of `signoff`)
 * labelled every non-signed-off consultation "Active", hiding new/ongoing entirely. This proves the
 * registry payload now carries the real ledger state for each of the four statuses — one row per
 * test (rather than four in one table) so a single assertion on `results.data.0` is unambiguous.
 */
class RegistryConsultationStatusTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::create([
            'username' => 'rcs_'.substr(md5(uniqid('', true)), 0, 8),
            'name' => 'RCS Admin', 'password' => 'secret12345', 'role' => User::ROLE_ADMIN, 'active' => 1,
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
        ]);
    }

    private function consultation(string $status, ?string $signoffDate = null): Consultation
    {
        return Consultation::create([
            'mrn' => '8000000'.random_int(0, 9), 'patient_name' => 'Status Patient',
            'consultation_date' => '2024-05-01', 'indication' => [], 'current_location' => 'Ward',
            'status' => $status, 'signoff_date' => $signoffDate,
        ]);
    }

    public function test_a_new_consultation_carries_status_new_in_the_registry_payload(): void
    {
        $this->consultation(Consultation::STATUS_NEW);

        $this->actingAs($this->admin)->get('/registry?mode=consultations')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('results.total', 1)
                ->where('results.data.0.status', Consultation::STATUS_NEW));
    }

    public function test_an_active_consultation_carries_status_active_in_the_registry_payload(): void
    {
        $this->consultation(Consultation::STATUS_ACTIVE);

        $this->actingAs($this->admin)->get('/registry?mode=consultations')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('results.total', 1)
                ->where('results.data.0.status', Consultation::STATUS_ACTIVE));
    }

    /** The specific regression from the walkthrough: an `ongoing` consult must read as ongoing. */
    public function test_an_ongoing_consultation_is_labelled_ongoing_not_active(): void
    {
        $this->consultation(Consultation::STATUS_ONGOING);

        $this->actingAs($this->admin)->get('/registry?mode=consultations')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('results.total', 1)
                ->where('results.data.0.status', Consultation::STATUS_ONGOING));
    }

    public function test_a_signed_off_consultation_carries_status_signed_off_in_the_registry_payload(): void
    {
        $this->consultation(Consultation::STATUS_SIGNED_OFF, '2024-05-02');

        $this->actingAs($this->admin)->get('/registry?mode=consultations')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('results.total', 1)
                ->where('results.data.0.status', Consultation::STATUS_SIGNED_OFF)
                ->where('results.data.0.signoff', '2024-05-02'));
    }

    /**
     * Legacy drift (an open status beside a sign-off date) reads as signed off, matching the
     * `signed_only` filter and the physician dashboard, so no screen calls the same row open.
     */
    public function test_a_legacy_drifted_row_reads_as_signed_off(): void
    {
        $this->consultation(Consultation::STATUS_ONGOING, '2024-05-03');

        $this->actingAs($this->admin)->get('/registry?mode=consultations')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('results.total', 1)
                ->where('results.data.0.status', Consultation::STATUS_SIGNED_OFF)
                ->where('results.data.0.signoff', '2024-05-03'));
    }
}
