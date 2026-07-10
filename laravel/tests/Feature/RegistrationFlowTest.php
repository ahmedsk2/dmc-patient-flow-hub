<?php

namespace Tests\Feature;

use App\Mail\RegistrationCodeMail;
use App\Models\PendingRegistration;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * 2026-07-11 auth-hardening: self-registration now requires BOTH a verified email (mailed 6-digit
 * code) AND a confirmed TOTP authenticator before an account is created. State lives in
 * `pending_registrations`, keyed by session('reg.token'). See docs/superpowers/specs/
 * 2026-07-11-mandatory-mfa-email-verification-design.md §A/§B.
 */
class RegistrationFlowTest extends TestCase
{
    use RefreshDatabase;

    private function lastCode(): string
    {
        return Mail::sent(RegistrationCodeMail::class)->last()->code;
    }

    private function pending(): PendingRegistration
    {
        return PendingRegistration::firstOrFail();
    }

    private function currentTotpCode(string $secret): string
    {
        $m = new \ReflectionMethod(Totp::class, 'code');
        $m->setAccessible(true);

        return $m->invoke(null, $secret, intdiv(time(), 30));
    }

    /** Drives email-send -> email-verify -> mfa-provision -> mfa-confirm for $email. */
    private function completeEmailAndMfa(string $email): void
    {
        Mail::fake();
        $this->postJson('/register/email/send', ['email' => $email])->assertOk();
        $this->postJson('/register/email/verify', ['code' => $this->lastCode()])->assertOk();
        $secret = $this->postJson('/register/mfa/provision')->assertOk()->json('secret');
        $this->postJson('/register/mfa/confirm', ['code' => $this->currentTotpCode($secret)])->assertOk();
    }

    // ---- email step -------------------------------------------------------------------------------

    public function test_email_send_then_verify_happy_path(): void
    {
        Mail::fake();

        $this->postJson('/register/email/send', ['email' => 'applicant@example.test'])
            ->assertOk()->assertJson(['sent' => true]);

        Mail::assertSent(RegistrationCodeMail::class, fn ($m) => $m->hasTo('applicant@example.test'));
        $this->assertNotNull($this->pending()->email_code_expires_at);
        $this->assertNull($this->pending()->email_verified_at);

        $this->postJson('/register/email/verify', ['code' => $this->lastCode()])
            ->assertOk()->assertJson(['verified' => true]);

        $this->assertNotNull($this->pending()->email_verified_at);
    }

    public function test_email_send_rejects_an_address_already_registered(): void
    {
        User::create(['username' => 'existing_u', 'name' => 'Existing', 'email' => 'taken@example.test',
            'password' => 'secret12345', 'role' => User::ROLE_RESIDENT, 'active' => 1]);

        Mail::fake();
        $this->postJson('/register/email/send', ['email' => 'taken@example.test'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        Mail::assertNothingSent();
    }

    public function test_email_verify_rejects_an_expired_code(): void
    {
        Mail::fake();
        $this->postJson('/register/email/send', ['email' => 'expiring@example.test'])->assertOk();
        $code = $this->lastCode();

        $this->travel(11)->minutes();

        $this->postJson('/register/email/verify', ['code' => $code])
            ->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->assertNull($this->pending()->email_verified_at);
    }

    public function test_email_verify_forces_resend_after_five_bad_attempts(): void
    {
        Mail::fake();
        $this->postJson('/register/email/send', ['email' => 'attempts@example.test'])->assertOk();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/register/email/verify', ['code' => '000000'])
                ->assertUnprocessable()->assertJsonValidationErrors('code');
        }

        // 6th attempt: even the CORRECT code is now rejected — the caller must resend for a fresh row
        $this->postJson('/register/email/verify', ['code' => $this->lastCode()])
            ->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->assertNull($this->pending()->email_verified_at);
    }

    public function test_email_send_enforces_resend_cooldown(): void
    {
        Mail::fake();
        $this->postJson('/register/email/send', ['email' => 'cooldown@example.test'])->assertOk();

        $this->postJson('/register/email/send', ['email' => 'cooldown@example.test'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->travel(61)->seconds();
        $this->postJson('/register/email/send', ['email' => 'cooldown@example.test'])->assertOk();
    }

    public function test_email_send_caps_the_number_of_codes_per_row(): void
    {
        Mail::fake();
        foreach (range(1, 5) as $i) {
            $this->postJson('/register/email/send', ['email' => 'capped@example.test'])->assertOk();
            $this->travel(61)->seconds();
        }

        $this->postJson('/register/email/send', ['email' => 'capped@example.test'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    // ---- MFA step -----------------------------------------------------------------------------------

    public function test_provision_requires_a_verified_email(): void
    {
        Mail::fake();
        $this->postJson('/register/email/send', ['email' => 'unverified@example.test'])->assertOk();

        $this->postJson('/register/mfa/provision')
            ->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_provision_is_idempotent_while_unconfirmed(): void
    {
        Mail::fake();
        $this->postJson('/register/email/send', ['email' => 'idempotent@example.test'])->assertOk();
        $this->postJson('/register/email/verify', ['code' => $this->lastCode()])->assertOk();

        $first = $this->postJson('/register/mfa/provision')->assertOk()->json('secret');
        $second = $this->postJson('/register/mfa/provision')->assertOk()->json('secret');
        $this->assertSame($first, $second);
    }

    public function test_confirm_requires_a_provisioned_secret(): void
    {
        Mail::fake();
        $this->postJson('/register/email/send', ['email' => 'noprovision@example.test'])->assertOk();
        $this->postJson('/register/email/verify', ['code' => $this->lastCode()])->assertOk();

        $this->postJson('/register/mfa/confirm', ['code' => '123456'])
            ->assertUnprocessable()->assertJsonValidationErrors('code');
    }

    public function test_confirm_rejects_an_incorrect_totp_code(): void
    {
        Mail::fake();
        $this->postJson('/register/email/send', ['email' => 'badtotp@example.test'])->assertOk();
        $this->postJson('/register/email/verify', ['code' => $this->lastCode()])->assertOk();
        $this->postJson('/register/mfa/provision')->assertOk();

        $this->postJson('/register/mfa/confirm', ['code' => '000000'])
            ->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->assertNull($this->pending()->totp_confirmed_at);
    }

    // ---- store ------------------------------------------------------------------------------------

    public function test_store_rejects_when_email_is_unverified(): void
    {
        Mail::fake();
        $this->postJson('/register/email/send', ['email' => 'half1@example.test'])->assertOk();

        $this->post('/register', [
            'username' => 'half1', 'full_name' => 'Half One', 'email' => 'half1@example.test',
            'role' => 4, 'password' => 'Password123', 'password_confirmation' => 'Password123',
        ])->assertSessionHasErrors('email');
        $this->assertNull(User::where('username', 'half1')->first());
    }

    public function test_store_rejects_when_mfa_is_unconfirmed(): void
    {
        Mail::fake();
        $this->postJson('/register/email/send', ['email' => 'half2@example.test'])->assertOk();
        $this->postJson('/register/email/verify', ['code' => $this->lastCode()])->assertOk();
        $this->postJson('/register/mfa/provision')->assertOk();

        $this->post('/register', [
            'username' => 'half2', 'full_name' => 'Half Two', 'email' => 'half2@example.test',
            'role' => 4, 'password' => 'Password123', 'password_confirmation' => 'Password123',
        ])->assertSessionHasErrors('email');
        $this->assertNull(User::where('username', 'half2')->first());
    }

    public function test_store_rejects_when_the_verified_email_does_not_match_the_submitted_one(): void
    {
        $this->completeEmailAndMfa('verified-one@example.test');

        $this->post('/register', [
            'username' => 'mismatch', 'full_name' => 'Mismatch', 'email' => 'different@example.test',
            'role' => 4, 'password' => 'Password123', 'password_confirmation' => 'Password123',
        ])->assertSessionHasErrors('email');
        $this->assertNull(User::where('username', 'mismatch')->first());
    }

    public function test_store_creates_an_inactive_user_with_email_and_mfa_set_and_deletes_the_pending_row(): void
    {
        $this->completeEmailAndMfa('full@example.test');
        $this->assertDatabaseCount('pending_registrations', 1);

        $this->post('/register', [
            'username' => 'fullflow', 'full_name' => 'Full Flow', 'email' => 'full@example.test',
            'role' => 3, 'password' => 'Password123', 'password_confirmation' => 'Password123',
        ])->assertRedirect(route('login'))->assertSessionHasNoErrors();

        $user = User::where('username', 'fullflow')->firstOrFail();
        $this->assertSame(0, (int) $user->active);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue($user->mfaEnabled());
        $this->assertNotEmpty($user->mfa_recovery_codes);
        $this->assertSame(now()->toDateString(), $user->pass_exp_date->toDateString());
        $this->assertDatabaseCount('pending_registrations', 0);
        $this->assertDatabaseHas('audit_log', [
            'action' => 'user.self_register', 'entity_type' => 'user', 'entity_id' => (string) $user->id,
        ]);
        $this->assertNull(session('reg.token'), 'reg.token must be forgotten after account creation');
    }

    public function test_store_rejects_a_role_of_admin(): void
    {
        $this->completeEmailAndMfa('noadmin@example.test');

        $this->post('/register', [
            'username' => 'noadmin', 'full_name' => 'No Admin', 'email' => 'noadmin@example.test',
            'role' => 0, 'password' => 'Password123', 'password_confirmation' => 'Password123',
        ])->assertSessionHasErrors('role');
        $this->assertNull(User::where('username', 'noadmin')->first());
    }

    public function test_store_without_any_pending_row_fails(): void
    {
        $this->post('/register', [
            'username' => 'nopending', 'full_name' => 'No Pending', 'email' => 'nopending@example.test',
            'role' => 4, 'password' => 'Password123', 'password_confirmation' => 'Password123',
        ])->assertSessionHasErrors('email');
        $this->assertNull(User::where('username', 'nopending')->first());
    }
}
