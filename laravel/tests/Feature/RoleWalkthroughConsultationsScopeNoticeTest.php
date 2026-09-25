<?php

namespace Tests\Feature;

use App\Models\Specialty;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Role walkthrough 2026-09-25, group g4-admissions-consultations, item U1 pt.2.
 *
 * registrar.md / resident.md both reproduced the same confusion from opposite ends: a viewer with
 * no specialty_id AND no coordinator capability is narrowed by Consultation::scopeVisibleTo all the
 * way down to `consultant_id = them OR entered_by = them` — no specialty clause at all, unlike every
 * other non-privileged viewer (who at least sees their own team's whole book). Nothing on the page
 * said so, which reads exactly like a broken ledger. ConsultationsController::index() now ships a
 * `scopeNotice` string for exactly this case, and null for everyone else — pinned here against the
 * SAME predicate scopeVisibleTo itself branches on, so the two can never silently drift apart.
 */
class RoleWalkthroughConsultationsScopeNoticeTest extends TestCase
{
    use RefreshDatabase;

    private function user(int $role, array $extra = []): User
    {
        return User::create(array_merge([
            'username' => 'g4sn_'.substr(md5(uniqid('', true)), 0, 10),
            'name' => 'Scope Notice User', 'password' => 'secret12345', 'role' => $role, 'active' => 1,
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
            'email_verified_at' => now(), 'pass_exp_date' => now()->toDateString(),
        ], $extra));
    }

    public function test_a_registrar_with_no_specialty_and_no_coordinator_capability_sees_the_scope_notice(): void
    {
        $registrar = $this->user(User::ROLE_REGISTRAR, [
            'specialty_id' => null, 'can_coordinate_consultations' => false,
        ]);

        $this->actingAs($registrar)
            ->get('/consultations')
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->where('scopeNotice', 'You see only the consultations you booked or are the consultant for — your account has no specialty and no coordinator role.'));
    }

    public function test_a_consultant_with_a_specialty_gets_no_notice_even_without_coordinator_capability(): void
    {
        $cardio = Specialty::create(['name' => 'Cardiology', 'is_subspecialty' => true, 'is_external' => false]);
        $consultant = $this->user(User::ROLE_CONSULTANT, [
            'specialty_id' => $cardio->id, 'can_coordinate_consultations' => false,
        ]);

        $this->actingAs($consultant)
            ->get('/consultations')
            ->assertInertia(fn (AssertableInertia $p) => $p->where('scopeNotice', null));
    }

    public function test_a_no_specialty_user_with_the_coordinator_capability_gets_no_notice(): void
    {
        $coordinator = $this->user(User::ROLE_REGISTRAR, [
            'specialty_id' => null, 'can_coordinate_consultations' => true,
        ]);

        $this->actingAs($coordinator)
            ->get('/consultations')
            ->assertInertia(fn (AssertableInertia $p) => $p->where('scopeNotice', null));
    }

    public function test_an_admin_with_no_specialty_gets_no_notice(): void
    {
        $admin = $this->user(User::ROLE_ADMIN, ['specialty_id' => null]);

        $this->actingAs($admin)
            ->get('/consultations')
            ->assertInertia(fn (AssertableInertia $p) => $p->where('scopeNotice', null));
    }
}
