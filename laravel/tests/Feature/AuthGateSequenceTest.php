<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * 2026-07-11 regression: an account that is BOTH email-unverified AND MFA-unenrolled (every
 * freshly-imported legacy user) bounced /email/verify -> /mfa/setup -> /email/verify forever
 * (ERR_TOO_MANY_REDIRECTS), because EnsureEmailVerified and EnsureMfaEnrolled each redirected to
 * the other's page without allow-listing it. The email-verify routes are now exempt from the MFA
 * gate so email verification completes FIRST, then the MFA gate takes over.
 */
class AuthGateSequenceTest extends TestCase
{
    use RefreshDatabase;

    private function unverifiedNoMfaUser(): User
    {
        return User::create([
            'username' => 'gateuser', 'name' => 'Gate User', 'email' => 'gate@example.test',
            'password' => 'secret12345', 'role' => User::ROLE_RESIDENT, 'active' => 1,
            'pass_exp_date' => now()->toDateString(),
            'email_verified_at' => null,   // on file but unverified
            // deliberately no mfa_secret / mfa_enrolled_at
        ]);
    }

    public function test_email_unverified_and_unenrolled_user_reaches_email_verify_without_a_loop(): void
    {
        $user = $this->unverifiedNoMfaUser();
        Mail::fake();

        // the email-verify page must RENDER (200), not bounce to /mfa/setup
        $this->actingAs($user)->get('/email/verify')->assertOk();
        // and the code-send endpoint must be reachable too (not redirected by the MFA gate)
        $this->actingAs($user)->postJson('/email/verify/send', [])->assertStatus(200);
    }

    public function test_gate_order_is_email_first_then_mfa(): void
    {
        $user = $this->unverifiedNoMfaUser();

        // step 1: any app page funnels to email verification first
        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('email.verify.show'));

        // step 2: once the email is verified, the SAME page now funnels to MFA setup (no loop)
        $user->update(['email_verified_at' => now()]);
        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('mfa.setup'));
    }
}
