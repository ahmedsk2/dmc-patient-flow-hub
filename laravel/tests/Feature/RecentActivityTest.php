<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\Consultation;
use App\Models\Patient;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Usability Wave 1 — Item 3: Recent Activity read/write split.
 *
 *   • The Recent-Activity VIEW (GET /recent) is now open to every authenticated clinical role
 *     (incl. Observer) — it moved out of the admin-only nav/route group and into the general
 *     authenticated group. It is read-only.
 *   • The same-day UNDO actions (reverse-discharge / reverse-signoff) stay ADMIN-ONLY, enforced
 *     SERVER-SIDE inside the controllers (Auth::user()->isAdmin()), NOT by nav visibility.
 *
 * These tests are the real security boundary for the split.
 */
class RecentActivityTest extends TestCase
{
    use RefreshDatabase;

    private function user(int $role, array $extra = []): User
    {
        return User::create(array_merge([
            'username' => 'recent_'.$role.'_'.substr(md5(uniqid('', true)), 0, 8),
            'name' => 'Recent User', 'password' => 'secret12345', 'role' => $role, 'active' => 1,
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
        ], $extra));
    }

    /** An admission discharged TODAY (so its undo button is "reversible" / same-day). */
    private function dischargedTodayAdmission(): Admission
    {
        $p = Patient::create(['mrn' => (string) random_int(10000000, 99999999), 'name' => 'Disch Pt']);

        return Admission::create([
            'patient_id' => $p->id,
            'admit_date' => now()->subDays(3)->toDateString(),
            'discharge_date' => now()->toDateString(),
            'current_location' => 'Ward',
            'outcome' => 'Alive',
        ]);
    }

    /** A consultation signed off TODAY (same-day, so its undo is offered). */
    private function signedOffTodayConsultation(): Consultation
    {
        return Consultation::create([
            'mrn' => (string) random_int(10000000, 99999999),
            'patient_name' => 'Cons Pt', 'age' => 50,
            'consultation_date' => now()->subDay()->toDateString(),
            'signoff_date' => now()->toDateString(),
            'indication' => [],
        ]);
    }

    // ── READ: open to all authenticated roles ──────────────────────────────────────────────────

    public function test_observer_can_view_recent_activity(): void
    {
        // Observer is the lowest clinical role; the owner default exposes the read-only view to it.
        $this->actingAs($this->user(User::ROLE_OBSERVER))->get('/recent')->assertOk();
    }

    public function test_consultant_can_view_recent_activity(): void
    {
        $this->actingAs($this->user(User::ROLE_CONSULTANT))->get('/recent')->assertOk();
    }

    public function test_registrar_can_view_recent_activity(): void
    {
        $this->actingAs($this->user(User::ROLE_REGISTRAR))->get('/recent')->assertOk();
    }

    public function test_admin_can_view_recent_activity(): void
    {
        $this->actingAs($this->user(User::ROLE_ADMIN))->get('/recent')->assertOk();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/recent')->assertRedirect('/login');
    }

    // ── UNDO: admin-only, enforced server-side ───────────────────────────────────────────────────

    public function test_clinical_role_is_forbidden_from_reversing_a_discharge(): void
    {
        $a = $this->dischargedTodayAdmission();

        // a valid step-up window bypasses the RequireStepUp middleware on this route, so the request
        // reaches the controller — proving the ADMIN gate (not just step-up) is what blocks it.
        $this->actingAs($this->user(User::ROLE_CONSULTANT))
            ->withSession(['stepup.verified_at' => now()->getTimestamp()])
            ->post("/admissions/{$a->id}/reverse-discharge")
            ->assertForbidden();

        // the discharge is untouched
        $this->assertNotNull($a->fresh()->discharge_date);
    }

    public function test_clinical_role_is_forbidden_from_reversing_a_signoff(): void
    {
        $c = $this->signedOffTodayConsultation();

        // reverse-signoff has no step-up gate — the controller's isAdmin() check returns 403 directly.
        $this->actingAs($this->user(User::ROLE_REGISTRAR))
            ->post("/consultations/{$c->id}/reverse-signoff")
            ->assertForbidden();

        $this->assertNotNull($c->fresh()->signoff_date);
    }

    public function test_admin_can_reverse_a_discharge(): void
    {
        $a = $this->dischargedTodayAdmission();

        $this->actingAs($this->user(User::ROLE_ADMIN))
            ->withSession(['stepup.verified_at' => now()->getTimestamp()])
            ->post("/admissions/{$a->id}/reverse-discharge")
            ->assertRedirect();   // back() with a success flash

        // the discharge was actually undone
        $this->assertNull($a->fresh()->discharge_date);
    }

    public function test_admin_can_reverse_a_signoff(): void
    {
        $c = $this->signedOffTodayConsultation();

        $this->actingAs($this->user(User::ROLE_ADMIN))
            ->post("/consultations/{$c->id}/reverse-signoff")
            ->assertRedirect();

        $this->assertNull($c->fresh()->signoff_date);
    }
}
