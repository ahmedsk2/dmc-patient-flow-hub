<?php

namespace Tests\Feature;

use App\Mail\RegistrationAttemptNoticeMail;
use App\Mail\RegistrationCodeMail;
use App\Models\PendingRegistration;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Cache\RateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
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

    // ---- S2 (role walkthrough 2026-09-25): anti-enumeration on sendEmailCode -------------------
    // A registered address, a collision with someone else's live pending registration, and an
    // unknown address must all produce the SAME response — see RegisterController::sendEmailCode.

    public function test_email_send_for_an_already_registered_address_looks_identical_to_a_real_send(): void
    {
        User::create(['username' => 'existing_u', 'name' => 'Existing', 'email' => 'taken@example.test',
            'password' => 'secret12345', 'role' => User::ROLE_RESIDENT, 'active' => 1]);

        Mail::fake();
        $this->postJson('/register/email/send', ['email' => 'taken@example.test'])
            ->assertOk()->assertExactJson(['sent' => true]);

        // a notice goes to the real (already-registered) inbox instead of a verification code
        Mail::assertSent(RegistrationAttemptNoticeMail::class, fn ($m) => $m->hasTo('taken@example.test') && $m->hasAccount === true);
        Mail::assertNotSent(RegistrationCodeMail::class);
    }

    public function test_email_send_for_an_already_registered_address_never_issues_a_usable_code(): void
    {
        User::create(['username' => 'existing_u2', 'name' => 'Existing Two', 'email' => 'taken2@example.test',
            'password' => 'secret12345', 'role' => User::ROLE_RESIDENT, 'active' => 1]);

        Mail::fake();
        $this->postJson('/register/email/send', ['email' => 'taken2@example.test'])->assertOk();

        // a brand-new session's row IS created (it carries the cooldown bookkeeping), but with an
        // EMPTY address, never the probed one — see RegisterController::sendEmailCode's `email`
        // fill comment — and no code hash, so it stays unverifiable: any guess at /verify fails
        // exactly like an ordinary wrong code (never "no pending row"/"no code was ever sent").
        $this->assertFalse(PendingRegistration::where('email', 'taken2@example.test')->exists());
        $row = PendingRegistration::firstOrFail();
        $this->assertSame('', $row->email);
        $this->assertNull($row->email_code_hash);
        $this->assertNull($row->email_verified_at);
        $this->postJson('/register/email/verify', ['code' => '123456'])
            ->assertUnprocessable()->assertJsonValidationErrors('code');
    }

    public function test_email_send_for_an_address_colliding_with_another_live_pending_registration_looks_identical(): void
    {
        // seed another session's in-flight (unexpired) pending registration for the address
        PendingRegistration::create([
            'token' => 'other-session-token', 'email' => 'inflight@example.test',
            'expires_at' => now()->addMinutes(20),
        ]);

        Mail::fake();
        $this->postJson('/register/email/send', ['email' => 'inflight@example.test'])
            ->assertOk()->assertExactJson(['sent' => true]);

        Mail::assertNotSent(RegistrationCodeMail::class);
        // fix-up (role walkthrough 2026-09-25): a collision-only block used to send NO mail at all,
        // while the already-registered block sent one — under QUEUE_CONNECTION=sync that made "mail
        // sent" vs. "no mail sent" a timing side channel distinguishing the two blocked reasons even
        // though the HTTP response is identical. Both blocked reasons now send one mail; only the
        // wording differs (hasAccount === false — this address has no account yet).
        Mail::assertSent(RegistrationAttemptNoticeMail::class, fn ($m) => $m->hasTo('inflight@example.test') && $m->hasAccount === false);

        // the probe must not park a second row on that address: every row carrying it counts as a
        // "collision", so the real registrant could otherwise be locked out by a stranger's probe
        $this->assertSame(1, PendingRegistration::where('email', 'inflight@example.test')->count());
    }

    public function test_email_send_for_a_soft_deleted_users_address_is_blocked_like_an_active_one(): void
    {
        // the DB-level 'unique:users,email' rule this replaced ignores soft deletes, and store()'s
        // own unique check still does — a soft-deleted user's email must stay "taken" here too, or
        // step 1 would promise an address that step 4 (store()) then refuses
        $u = User::create(['username' => 'was_here', 'name' => 'Was Here', 'email' => 'gone@example.test',
            'password' => 'secret12345', 'role' => User::ROLE_RESIDENT, 'active' => 1]);
        $u->delete();

        Mail::fake();
        $this->postJson('/register/email/send', ['email' => 'gone@example.test'])
            ->assertOk()->assertExactJson(['sent' => true]);

        Mail::assertNotSent(RegistrationCodeMail::class);
        Mail::assertSent(RegistrationAttemptNoticeMail::class, fn ($m) => $m->hasTo('gone@example.test'));
    }

    public function test_email_send_for_an_unknown_address_still_sends_a_real_code(): void
    {
        Mail::fake();
        $this->postJson('/register/email/send', ['email' => 'genuinely-new@example.test'])
            ->assertOk()->assertExactJson(['sent' => true]);

        Mail::assertSent(RegistrationCodeMail::class, fn ($m) => $m->hasTo('genuinely-new@example.test'));
    }

    public function test_probing_a_registered_address_does_not_disturb_the_sessions_own_verified_progress(): void
    {
        // this session legitimately verifies its OWN address first...
        $this->completeEmailAndMfaEmailStepOnly('mine@example.test');
        $this->assertNotNull($this->pending()->email_verified_at);

        // ...then idly probes a known staff address — must not clobber the session's own row
        // (past the resend cooldown so this second send isn't itself rejected)
        $this->travel(61)->seconds();
        User::create(['username' => 'existing_u3', 'name' => 'Existing Three', 'email' => 'staff@example.test',
            'password' => 'secret12345', 'role' => User::ROLE_RESIDENT, 'active' => 1]);
        $this->postJson('/register/email/send', ['email' => 'staff@example.test'])->assertOk();

        $pending = $this->pending();
        $this->assertSame('mine@example.test', $pending->email, "the probe must not overwrite the session's own address");
        $this->assertNotNull($pending->email_verified_at, "the probe must not un-verify the session's own address");
    }

    /** Drives only the email send+verify half (no MFA), for the probe-isolation test above. */
    private function completeEmailAndMfaEmailStepOnly(string $email): void
    {
        Mail::fake();
        $this->postJson('/register/email/send', ['email' => $email])->assertOk();
        $this->postJson('/register/email/verify', ['code' => $this->lastCode()])->assertOk();
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

    // ---- username rule (2026-09 prod-readiness: parity with Control → Users) ----------------------
    // Registration accepted any 240-char string as a username while the admin edit path enforced
    // alpha_dash + max:64 — so a crafted Markdown/URL username could reach the synchronous
    // username-reminder mail verbatim. Both paths now apply the same rule.

    public function test_store_rejects_usernames_that_are_not_alpha_dash_or_exceed_64_chars(): void
    {
        $rejected = [
            'dr*maryam',                        // *
            '[click](https://evil.example)',    // [ — a Markdown link
            'dr maryam',                        // space
            str_repeat('a', 200),               // 200 chars (was allowed up to 240)
        ];

        foreach ($rejected as $username) {
            // Inertia-style store(): a validation failure is a 302 back with the errors flashed to
            // the session (bootstrap/app.php renders JSON exceptions only under api/*), never a
            // 422 body — same shape the role/email rejections above are pinned with.
            $this->from('/register')->post('/register', [
                'username' => $username, 'full_name' => 'Bad Name', 'email' => 'bad@example.test',
                'role' => 3, 'password' => 'Password123', 'password_confirmation' => 'Password123',
            ])->assertStatus(302)->assertRedirect('/register')->assertSessionHasErrors('username');
        }

        $this->assertSame(0, User::whereIn('username', $rejected)->count(), 'no account may be created from a rejected username');
    }

    public function test_store_accepts_a_username_with_underscore_hyphen_and_digits(): void
    {
        $this->completeEmailAndMfa('maryam@example.test');

        $this->post('/register', [
            'username' => 'dr_maryam-2', 'full_name' => 'Maryam', 'email' => 'maryam@example.test',
            'role' => 3, 'password' => 'Password123', 'password_confirmation' => 'Password123',
        ])->assertRedirect(route('login'))->assertSessionHasNoErrors();

        $this->assertNotNull(User::where('username', 'dr_maryam-2')->first());
    }

    // ---- email-bomb hardening (2026-07-11 adversarial-review follow-up) ---------------------------
    // Switching the target address mid-flow must NOT reset the resend cooldown or the per-row send
    // cap — otherwise the endpoint is a relay for mailing unlimited codes to arbitrary victims.

    public function test_changing_the_target_email_does_not_reset_the_resend_cooldown(): void
    {
        Mail::fake();
        $this->postJson('/register/email/send', ['email' => 'first@example.test'])->assertOk();

        // immediately switching to another address is still inside the 60s cooldown → rejected
        $this->postJson('/register/email/send', ['email' => 'second@example.test'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        Mail::assertSent(RegistrationCodeMail::class, 1);   // only the first code actually went out

        // once the cooldown elapses, switching is allowed
        $this->travel(61)->seconds();
        $this->postJson('/register/email/send', ['email' => 'second@example.test'])->assertOk();
    }

    public function test_the_send_cap_counts_across_changed_addresses(): void
    {
        Mail::fake();
        // five sends, each to a DIFFERENT address (cooldown honoured between them) exhausts the cap
        foreach (range(1, 5) as $i) {
            $this->postJson('/register/email/send', ['email' => "victim{$i}@example.test"])->assertOk();
            $this->travel(61)->seconds();
        }

        // the 6th is capped regardless of the (again new) target address
        $this->postJson('/register/email/send', ['email' => 'victim6@example.test'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_email_send_is_throttled_per_ip_independently_of_the_session(): void
    {
        // The per-row cap/cooldown and the 'register' throttle both key off the session, so a
        // cookie-less client (fresh session each request) evades them. The 'register-email' limiter
        // must bound the mail relay by IP alone — session-independent — so it can't be reset that way.
        $limiter = app(RateLimiter::class)->limiter('register-email');
        $this->assertNotNull($limiter, 'the register-email limiter must be registered');

        $keyFor = fn (string $ip) => collect(Arr::wrap($limiter(
            Request::create('/register/email/send', 'POST', server: ['REMOTE_ADDR' => $ip])
        )))->map(fn ($l) => (string) $l->key);

        $a = $keyFor('198.51.100.7');
        $b = $keyFor('198.51.100.8');

        $this->assertNotEmpty($a);
        $this->assertTrue($a->every(fn ($k) => str_contains($k, '198.51.100.7')), 'limiter is keyed by the IP');
        $this->assertNotEquals($a->all(), $b->all(), 'a different IP gets a different bucket');
        // Request::create() carries NO session, yet the limiter resolved without error — proof it
        // does not depend on a session id (so dropping cookies cannot rotate the bucket).
    }
}
