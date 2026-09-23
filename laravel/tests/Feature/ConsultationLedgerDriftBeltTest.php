<?php

namespace Tests\Feature;

use App\Models\Consultation;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Defect (B) — walkthrough 2026-09-23: legacy:import writes `signoff_date` without `status`
 * (Consultation::casts() / ConsultationDashboardController::openQuery() docblocks), so a
 * re-imported closed consult can carry status='new' (the schema default) AND a non-NULL
 * signoff_date — "drift". ConsultationDashboardController::openQuery() already excludes drifted
 * rows from every open tile (`->open()->whereNull('signoff_date')`); ConsultationsController::index()
 * did not, so the ledger's New tab/count and the dashboard's New tile disagreed about the same data
 * (demo data: ledger 180 vs dashboard 57). This proves the ledger now applies the identical belt and
 * the two screens report the same number for the same scope.
 */
class ConsultationLedgerDriftBeltTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'username' => 'drift_'.substr(md5(uniqid('', true)), 0, 10),
            'name' => 'Drift Admin', 'password' => 'secret12345', 'role' => User::ROLE_ADMIN, 'active' => 1,
            'email_verified_at' => now(),
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
        ]);
    }

    private function consultation(array $overrides = []): Consultation
    {
        return Consultation::create(array_merge([
            'mrn' => (string) random_int(10000000, 99999999),
            'patient_name' => 'Drift Patient',
            'age' => 58,
            'bed' => 'W-7',
            'current_location' => 'Ward',
            'consultation_from' => 'ER',
            'to_service' => 'Cardiology',
            'consultation_date' => now()->subDay()->toDateString(),
            'indication' => [],
            'status' => Consultation::STATUS_NEW,
        ], $overrides));
    }

    public function test_ledger_new_count_matches_the_dashboard_when_a_drifted_row_exists(): void
    {
        // two genuinely open `new` consults (no signoff_date — nothing has closed them)
        $this->consultation();
        $this->consultation();
        // one legacy-drifted row: status stayed at the schema default 'new' but signoff_date is
        // set — this is CLOSED data masquerading as an open, untriaged consult
        $this->consultation(['signoff_date' => now()->toDateString()]);

        $admin = $this->admin();

        // the ledger's New tab / stats must exclude the drifted row
        $this->actingAs($admin)->get('/consultations?status=new')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('consultations.total', 2)
                ->where('stats.new', 2)
                ->where('stats.signed_off', 1)   // the drifted row is filed as closed
                ->where('stats.total', 3)
                ->where('stats.open', 2)
                ->where('stats.mine_open', 0));

        // ...and must agree with the dashboard's own openCounts.new for the same (unscoped, admin)
        // view, which already carried this belt before this fix
        $this->actingAs($admin)->get('/consultations/dashboard')
            ->assertInertia(fn (AssertableInertia $p) => $p->where('openCounts.new', 2));
    }

    public function test_a_drifted_active_row_is_excluded_the_same_way(): void
    {
        $this->consultation(['status' => Consultation::STATUS_ACTIVE]);
        $this->consultation(['status' => Consultation::STATUS_ACTIVE, 'signoff_date' => now()->toDateString()]);

        $this->actingAs($this->admin())->get('/consultations?status=active')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('consultations.total', 1)
                ->where('stats.active', 1));
    }

    /** A genuine signed-off row must never be swept up by the drift belt — it stays fully visible. */
    public function test_the_signed_off_tab_is_unaffected_by_the_drift_belt(): void
    {
        $this->consultation([
            'status' => Consultation::STATUS_SIGNED_OFF,
            'signoff_date' => now()->toDateString(),
        ]);

        $this->actingAs($this->admin())->get('/consultations?status=signed_off')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('consultations.total', 1)
                ->where('stats.signed_off', 1));
    }

    /**
     * A drifted row is closed data, so it is filed under Signed off — listed, counted and labelled
     * there — rather than vanishing from the ledger (the Registry and the dashboard treat it the
     * same way).
     */
    public function test_a_drifted_row_is_filed_under_signed_off(): void
    {
        $this->consultation(['status' => Consultation::STATUS_SIGNED_OFF, 'signoff_date' => now()->toDateString()]);
        $this->consultation(['signoff_date' => now()->subDays(3)->toDateString()]);   // drifted 'new'
        $this->consultation();   // genuinely open

        $this->actingAs($this->admin())->get('/consultations?status=signed_off')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('consultations.total', 2)
                ->where('consultations.data.0.status', Consultation::STATUS_SIGNED_OFF)
                ->where('consultations.data.0.open_days', null)
                ->where('consultations.data.1.status', Consultation::STATUS_SIGNED_OFF)
                ->where('stats.signed_off', 2)
                ->where('stats.new', 1)
                ->where('stats.total', 3)
                ->where('stats.open', 1));
    }
}
