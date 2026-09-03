<?php

namespace Tests\Feature;

use App\Mail\RegistrationCodeMail;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * 2026-07-11 auth-hardening: existing users whose email is ON FILE but UNVERIFIED are gated to
 * /email/verify (EnsureEmailVerified) before anything else in the authenticated group. A NULL
 * email is exempt (there's no address to send a code to). The live code lives in the SESSION, never
 * on the user row — see EmailVerificationController.
 */
class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    /** MFA-enrolled so only the email gate is under test (not the mfa.enroll gate that follows it). */
    private function enrolledUser(array $extra = []): User
    {
        return User::create(array_merge([
            'username' => 'ev_'.substr(md5(uniqid('', true)), 0, 10),
            'name' => 'EV User', 'password' => 'secret12345', 'role' => User::ROLE_CONSULTANT, 'active' => 1,
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
        ], $extra));
    }

    private function lastCode(): string
    {
        return Mail::sent(RegistrationCodeMail::class)->last()->code;
    }

    public function test_unverified_email_user_is_redirected_to_the_verify_gate(): void
    {
        $user = $this->enrolledUser(['email' => 'onfile@example.test', 'email_verified_at' => null]);

        $this->actingAs($user)->get('/')->assertRedirect(route('email.verify.show'));
    }

    public function test_verified_email_user_passes_through(): void
    {
        $user = $this->enrolledUser(['email' => 'verified@example.test', 'email_verified_at' => now()]);

        $this->actingAs($user)->get('/')->assertOk();
    }

    public function test_null_email_user_is_exempt_from_the_gate(): void
    {
        $user = $this->enrolledUser(['email' => null, 'email_verified_at' => null]);

        $this->actingAs($user)->get('/')->assertOk();
    }

    public function test_show_page_renders_a_masked_email(): void
    {
        $user = $this->enrolledUser(['email' => 'confirmflow@example.test', 'email_verified_at' => null]);

        $this->actingAs($user)->get('/email/verify')
            ->assertInertia(fn (AssertableInertia $p) => $p->where('maskedEmail', fn ($masked) => is_string($masked)
                && str_starts_with($masked, 'c')
                && str_ends_with($masked, '@example.test')
                && str_contains($masked, '*')
                && ! str_contains($masked, 'onfirmflow')));
    }

    public function test_send_then_confirm_verifies_the_users_email_and_lets_the_gate_through(): void
    {
        $user = $this->enrolledUser(['email' => 'confirmflow@example.test', 'email_verified_at' => null]);
        Mail::fake();

        $this->actingAs($user)->postJson('/email/verify/send')->assertOk()->assertJson(['sent' => true]);
        Mail::assertSent(RegistrationCodeMail::class, fn ($m) => $m->hasTo('confirmflow@example.test'));

        $this->actingAs($user)->post('/email/verify/confirm', ['code' => $this->lastCode()])
            ->assertRedirect(route('dashboard'));

        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertDatabaseHas('audit_log', [
            'action' => 'email.verified', 'entity_type' => 'user', 'entity_id' => (string) $user->id,
        ]);

        // the gate no longer blocks
        $this->actingAs($user)->get('/')->assertOk();
    }

    public function test_confirm_rejects_an_incorrect_code(): void
    {
        $user = $this->enrolledUser(['email' => 'wrongcode@example.test', 'email_verified_at' => null]);
        Mail::fake();
        $this->actingAs($user)->postJson('/email/verify/send')->assertOk();

        $this->actingAs($user)->post('/email/verify/confirm', ['code' => '000000'])
            ->assertSessionHasErrors('code');
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_confirm_rejects_an_expired_code(): void
    {
        $user = $this->enrolledUser(['email' => 'expiredcode@example.test', 'email_verified_at' => null]);
        Mail::fake();
        $this->actingAs($user)->postJson('/email/verify/send')->assertOk();
        $code = $this->lastCode();

        $this->travel(11)->minutes();

        $this->actingAs($user)->post('/email/verify/confirm', ['code' => $code])
            ->assertSessionHasErrors('code');
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_confirm_forces_resend_after_five_bad_attempts(): void
    {
        $user = $this->enrolledUser(['email' => 'attempts@example.test', 'email_verified_at' => null]);
        Mail::fake();
        $this->actingAs($user)->postJson('/email/verify/send')->assertOk();

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($user)->post('/email/verify/confirm', ['code' => '000000'])
                ->assertSessionHasErrors('code');
        }

        $this->actingAs($user)->post('/email/verify/confirm', ['code' => $this->lastCode()])
            ->assertSessionHasErrors('code');
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_send_enforces_resend_cooldown(): void
    {
        $user = $this->enrolledUser(['email' => 'cooldown2@example.test', 'email_verified_at' => null]);
        Mail::fake();

        $this->actingAs($user)->postJson('/email/verify/send')->assertOk();
        $this->actingAs($user)->postJson('/email/verify/send')
            ->assertUnprocessable()->assertJsonValidationErrors('code');

        $this->travel(61)->seconds();
        $this->actingAs($user)->postJson('/email/verify/send')->assertOk();
    }

    public function test_send_requires_an_email_on_file(): void
    {
        $user = $this->enrolledUser(['email' => null, 'email_verified_at' => null]);

        $this->actingAs($user)->postJson('/email/verify/send')
            ->assertUnprocessable()->assertJsonValidationErrors('code');
    }
}
