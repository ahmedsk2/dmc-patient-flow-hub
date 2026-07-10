<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 2026-07-11 auth-hardening: MFA enrollment is now MANDATORY for EVERY authenticated user, always —
 * the settings.mfa_enforcement toggle (0/1/2) no longer gates EnsureMfaEnrolled at all (the column
 * stays in the schema; SecurityController/DashboardController still read it for their own display).
 */
class MandatoryMfaTest extends TestCase
{
    use RefreshDatabase;

    private function user(int $role, array $extra = []): User
    {
        return User::create(array_merge([
            'username' => 'mm_' . substr(md5(uniqid('', true)), 0, 10),
            'name' => 'MM User', 'password' => 'secret12345', 'role' => $role, 'active' => 1,
        ], $extra));
    }

    public function test_observer_without_mfa_is_redirected_to_setup_even_when_enforcement_is_off(): void
    {
        Setting::current()->update(['mfa_enforcement' => 0]);
        $observer = $this->user(User::ROLE_OBSERVER);

        $this->actingAs($observer)->get('/active-list')->assertRedirect(route('mfa.setup'));
    }

    public function test_resident_without_mfa_is_redirected_to_setup_even_when_enforcement_is_admins_only(): void
    {
        Setting::current()->update(['mfa_enforcement' => 1]);   // legacy "admins only" level
        $resident = $this->user(User::ROLE_RESIDENT);

        $this->actingAs($resident)->get('/active-list')->assertRedirect(route('mfa.setup'));
    }

    public function test_admin_without_mfa_is_redirected_to_setup_even_when_enforcement_is_off(): void
    {
        Setting::current()->update(['mfa_enforcement' => 0]);
        $admin = $this->user(User::ROLE_ADMIN);

        $this->actingAs($admin)->get('/control')->assertRedirect(route('mfa.setup'));
    }

    public function test_enrolled_observer_passes_through_regardless_of_enforcement_level(): void
    {
        Setting::current()->update(['mfa_enforcement' => 0]);
        $observer = $this->user(User::ROLE_OBSERVER, [
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
        ]);

        $this->actingAs($observer)->get('/active-list')->assertOk();
    }

    public function test_mfa_setup_route_itself_is_reachable_without_a_redirect_loop(): void
    {
        $observer = $this->user(User::ROLE_OBSERVER);

        $this->actingAs($observer)->get('/mfa/setup')->assertOk();
    }

    public function test_control_panel_reports_mfa_as_mandatory(): void
    {
        $admin = $this->user(User::ROLE_ADMIN, ['mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now()]);

        $this->actingAs($admin)->get('/control')
            ->assertInertia(fn ($p) => $p->where('mfaMandatory', true));
    }
}
