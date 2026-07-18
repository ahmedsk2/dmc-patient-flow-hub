<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\Consultation;
use App\Models\HandoverSignature;
use App\Models\Patient;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Usability Wave 1 — Item 7: the admin landing band on the Dashboard. The DashboardController
 * shares an `adminBand` prop ONLY for admins (null otherwise). These tests pin the gating + that
 * the counts reuse the existing sources (data-quality canaries, soft-deletes, pending handovers).
 */
class DashboardAdminBandTest extends TestCase
{
    use RefreshDatabase;

    private function user(int $role): User
    {
        return User::create([
            'username' => 'band_' . $role . '_' . substr(md5(uniqid('', true)), 0, 8),
            'name' => 'Band User', 'password' => 'secret12345', 'role' => $role, 'active' => 1,
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
        ]);
    }

    public function test_admin_dashboard_includes_admin_band_with_the_four_counts(): void
    {
        $this->actingAs($this->user(User::ROLE_ADMIN))->get('/')->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->has('adminBand', fn (AssertableInertia $b) => $b
                    ->has('dqIssues')
                    ->has('securityAnomalies')
                    ->has('recentlyDeleted')
                    ->has('pendingHandovers')
                    // HC-T8: unit-wide "needs handover" count, added alongside pendingHandovers
                    ->has('handoverDueUnit')));
    }

    public function test_non_admin_dashboard_has_null_admin_band(): void
    {
        $this->actingAs($this->user(User::ROLE_CONSULTANT))->get('/')->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('adminBand', null));
    }

    public function test_recently_deleted_count_reflects_soft_deletes(): void
    {
        // one soft-deleted admission contributes 1 to recentlyDeleted
        $patient = Patient::create(['mrn' => (string) random_int(10000000, 99999999), 'name' => 'Del Pt']);
        $a = Admission::create(['patient_id' => $patient->id, 'admit_date' => now()->toDateString(), 'current_location' => 'Ward']);
        $a->delete();   // soft delete

        $this->actingAs($this->user(User::ROLE_ADMIN))->get('/')->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('adminBand.recentlyDeleted', 1));
    }

    public function test_pending_handovers_count_reflects_unsigned_signatures(): void
    {
        $from = $this->user(User::ROLE_CONSULTANT);
        $to = $this->user(User::ROLE_CONSULTANT);
        $patient = Patient::create(['mrn' => (string) random_int(10000000, 99999999), 'name' => 'HO Pt']);
        $a = Admission::create(['patient_id' => $patient->id, 'admit_date' => now()->toDateString(), 'current_location' => 'Ward', 'consultant_id' => $to->id]);
        HandoverSignature::create([
            'admission_id' => $a->id,
            'from_consultant_id' => $from->id,
            'to_consultant_id' => $to->id,
            // signed_at + voided_at NULL => pending
        ]);

        $this->actingAs($this->user(User::ROLE_ADMIN))->get('/')->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('adminBand.pendingHandovers', 1));
    }
}
