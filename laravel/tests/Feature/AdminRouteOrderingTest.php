<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\Patient;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Walkthrough fix (2026-09-23): admissions.reverse (POST reverse-discharge) and admissions.destroy
 * (DELETE) are admin-only (the controller refuses non-admins) but used to sit outside the `admin`
 * route group with only ->middleware('stepup') on them — so a non-admin was first bounced to the
 * step-up password screen and only refused with 403 AFTER re-entering their password. Every other
 * admin-only step-up route runs inside Route::middleware('admin')->group(), so EnsureAdmin answers
 * 403 first. Fix: both routes now carry ->middleware(['admin', 'stepup']) so the admin gate runs
 * before step-up (neither alias sits in Laravel's default middleware-priority list, so
 * SortedMiddleware keeps this given order — see routes/web.php).
 *
 * These tests pin the new ordering directly: a non-admin must never see the step-up redirect for
 * these two routes, only an immediate 403, and nothing in the database moves. An admin still needs
 * a fresh step-up window, exactly as before.
 */
class AdminRouteOrderingTest extends TestCase
{
    use RefreshDatabase;

    private function user(int $role, array $extra = []): User
    {
        return User::create(array_merge([
            'username' => 'aro_'.$role.'_'.substr(md5(uniqid('', true)), 0, 8),
            'name' => 'ARO User', 'password' => 'secret12345', 'role' => $role, 'active' => 1,
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
        ], $extra));
    }

    private function admin(): User
    {
        return $this->user(User::ROLE_ADMIN);
    }

    /** An admission discharged TODAY (reversible under the same-day undo rule). */
    private function dischargedTodayAdmission(): Admission
    {
        $p = Patient::create(['mrn' => (string) random_int(10000000, 99999999), 'name' => 'ARO Pt']);

        return Admission::create([
            'patient_id' => $p->id,
            'admit_date' => now()->subDays(3)->toDateString(),
            'discharge_date' => now()->toDateString(),
            'current_location' => 'Ward',
            'outcome' => 'Alive',
        ]);
    }

    private function admission(array $overrides = []): Admission
    {
        $p = Patient::create(['mrn' => (string) random_int(10000000, 99999999), 'name' => 'ARO Pt']);

        return Admission::create(array_merge([
            'patient_id' => $p->id, 'admit_date' => now()->toDateString(), 'current_location' => 'Ward',
        ], $overrides));
    }

    // ── admissions.reverse (POST reverse-discharge) — no step-up session at all: the OLD ordering
    // would have bounced this to /stepup first (RequireStepUp ran before the controller's admin
    // check). The admin gate must now answer 403 before step-up is ever consulted. ────────────────

    private function assertNonAdminReversingDischargeIs403NotStepup(int $role): void
    {
        $a = $this->dischargedTodayAdmission();

        $response = $this->actingAs($this->user($role))
            ->post("/admissions/{$a->id}/reverse-discharge");

        $response->assertForbidden();
        $this->assertNotSame('/stepup', $response->headers->get('Location'), 'must not be redirected to step-up');
        $this->assertNotNull($a->fresh()->discharge_date, 'the discharge must stand — nothing changed in the DB');
    }

    public function test_registrar_reversing_discharge_gets_403_immediately_not_stepup_redirect(): void
    {
        $this->assertNonAdminReversingDischargeIs403NotStepup(User::ROLE_REGISTRAR);
    }

    public function test_consultant_reversing_discharge_gets_403_immediately_not_stepup_redirect(): void
    {
        $this->assertNonAdminReversingDischargeIs403NotStepup(User::ROLE_CONSULTANT);
    }

    public function test_observer_reversing_discharge_gets_403_immediately_not_stepup_redirect(): void
    {
        $this->assertNonAdminReversingDischargeIs403NotStepup(User::ROLE_OBSERVER);
    }

    // ── admissions.destroy (DELETE) ─────────────────────────────────────────────────────────────

    private function assertNonAdminDeletingAdmissionIs403NotStepup(int $role): void
    {
        $a = $this->admission();

        $response = $this->actingAs($this->user($role))
            ->delete("/admissions/{$a->id}");

        $response->assertForbidden();
        $this->assertNotSame('/stepup', $response->headers->get('Location'), 'must not be redirected to step-up');
        $this->assertNull($a->fresh()->deleted_at, 'the admission must not be deleted');
    }

    public function test_registrar_deleting_admission_gets_403_immediately_not_stepup_redirect(): void
    {
        $this->assertNonAdminDeletingAdmissionIs403NotStepup(User::ROLE_REGISTRAR);
    }

    public function test_consultant_deleting_admission_gets_403_immediately_not_stepup_redirect(): void
    {
        $this->assertNonAdminDeletingAdmissionIs403NotStepup(User::ROLE_CONSULTANT);
    }

    public function test_observer_deleting_admission_gets_403_immediately_not_stepup_redirect(): void
    {
        $this->assertNonAdminDeletingAdmissionIs403NotStepup(User::ROLE_OBSERVER);
    }

    // ── admin still needs a fresh step-up window ────────────────────────────────────────────────

    public function test_admin_without_fresh_stepup_is_redirected_to_stepup_for_reverse_discharge(): void
    {
        $a = $this->dischargedTodayAdmission();

        $this->actingAs($this->admin())
            ->post("/admissions/{$a->id}/reverse-discharge")
            ->assertRedirect('/stepup');

        $this->assertNotNull($a->fresh()->discharge_date, 'nothing changed pending step-up');
    }

    public function test_admin_without_fresh_stepup_is_redirected_to_stepup_for_delete(): void
    {
        $a = $this->admission();

        $this->actingAs($this->admin())
            ->delete("/admissions/{$a->id}")
            ->assertRedirect('/stepup');

        $this->assertNull($a->fresh()->deleted_at, 'nothing changed pending step-up');
    }

    // ── admin with a fresh step-up window succeeds, as before ──────────────────────────────────

    public function test_admin_with_fresh_stepup_can_reverse_a_same_day_discharge(): void
    {
        $a = $this->dischargedTodayAdmission();

        $this->actingAs($this->admin())
            ->withSession(['stepup.verified_at' => now()->getTimestamp()])
            ->post("/admissions/{$a->id}/reverse-discharge")
            ->assertRedirect();

        $this->assertNull($a->fresh()->discharge_date, 'admin with a fresh step-up window can reverse the discharge');
    }

    public function test_admin_with_fresh_stepup_can_delete_an_admission(): void
    {
        $a = $this->admission();

        $this->actingAs($this->admin())
            ->withSession(['stepup.verified_at' => now()->getTimestamp()])
            ->delete("/admissions/{$a->id}")
            ->assertRedirect();

        $this->assertNotNull($a->fresh()->deleted_at, 'admin with a fresh step-up window can delete the admission');
    }
}
