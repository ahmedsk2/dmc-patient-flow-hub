<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * Trusted device — the opt-in TOTP-only skip (2026-07-19 design spec).
 *
 * The load-bearing promise: this waives the SECOND factor for one browser for a bounded window.
 * It never waives the password, never transfers between accounts, and never extends its own
 * window. Every one of those has a named test below.
 */
class TrustedDeviceTest extends TestCase
{
    use RefreshDatabase;

    private const COOKIE = 'dmc_trusted_device';

    /** Produce a valid 6-digit code for $secret at the current time-step (private code() via reflection). */
    private function currentCode(string $secret): string
    {
        $m = new \ReflectionMethod(Totp::class, 'code');
        $m->setAccessible(true);

        return $m->invoke(null, $secret, intdiv(time(), 30));
    }

    private function enrolledUser(array $recovery = []): array
    {
        $secret = Totp::secret();
        $plain = $recovery ?: ['AAAA-BBBB'];
        $user = User::create([
            'username' => 'td_' . substr(md5(uniqid('', true)), 0, 8),
            'name' => 'Trusted User', 'password' => 'secret12345', 'role' => User::ROLE_CONSULTANT, 'active' => 1,
            'mfa_secret' => $secret,
            'mfa_recovery_codes' => array_map(fn ($c) => Hash::make($c), $plain),
            'mfa_enrolled_at' => now(),
            // the authed route group gates on both — set them so actingAs() reaches /profile
            'email_verified_at' => now(), 'pass_exp_date' => now()->toDateString(),
        ]);

        return [$user, $secret, $plain];
    }

    /**
     * Pull the trusted-device cookie off a response and return its RAW "selector:validator".
     *
     * EncryptCookies (empty $except) encrypts every outgoing cookie, so the queued value must be
     * decrypted and stripped of its per-name HMAC prefix to recover what the browser would later
     * hand back through the same middleware.
     */
    private function rawCookieFrom($response): ?string
    {
        $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === self::COOKIE);
        if (! $cookie || ! $cookie->getValue()) {
            return null;
        }

        return CookieValuePrefix::remove(Crypt::decrypt($cookie->getValue(), false));
    }

    /** Replay a raw cookie the way a browser would (withCookie encrypts + prefixes it for EncryptCookies). */
    private function withTrust(string $raw)
    {
        return $this->withCookie(self::COOKIE, $raw);
    }

    /** Create a device row directly and return the raw cookie value it corresponds to. */
    private function deviceFor(User $user, array $overrides = []): array
    {
        $selector = bin2hex(random_bytes(16));
        $validator = bin2hex(random_bytes(32));
        $device = TrustedDevice::create(array_merge([
            'user_id' => $user->id,
            'selector' => $selector,
            'validator_hash' => hash('sha256', $validator),
            'label' => 'Chrome on Windows',
            'ip' => '127.0.0.1',
            'expires_at' => now()->addHours(24),
        ], $overrides));

        return [$device, $selector . ':' . $validator];
    }

    private function login(User $user, string $password = 'secret12345')
    {
        return $this->post('/login', ['username' => $user->username, 'password' => $password]);
    }

    private function adminUser(): User
    {
        return User::create([
            'username' => 'tdadm_' . substr(md5(uniqid('', true)), 0, 8),
            'name' => 'TD Admin', 'password' => 'secret12345', 'role' => User::ROLE_ADMIN, 'active' => 1,
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
            'email_verified_at' => now(), 'pass_exp_date' => now()->toDateString(),
        ]);
    }

    /** The full Control user-edit payload, so a test can change ONE field without tripping validation. */
    private function userPayload(User $user, array $overrides = []): array
    {
        return array_merge([
            'username' => $user->username, 'full_name' => $user->full_name, 'email' => $user->email,
            'role' => (int) $user->role, 'active' => (bool) $user->active, 'on_service' => (bool) $user->on_service,
            'specialty_id' => $user->specialty_id, 'can_assign' => (bool) $user->can_assign,
            'can_add' => (bool) $user->can_add, 'can_manage' => (bool) $user->can_manage,
            'can_modify' => (bool) $user->can_modify,
        ], $overrides);
    }

    // ---------------------------------------------------------------- issuing

    public function test_ticking_the_box_issues_a_device_row_and_an_encrypted_cookie(): void
    {
        [$user, $secret] = $this->enrolledUser();
        $this->login($user)->assertRedirect(route('mfa.challenge'));

        $res = $this->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36')
            ->post('/mfa/challenge', ['code' => $this->currentCode($secret), 'trust_device' => true]);
        $res->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);

        $device = TrustedDevice::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('Chrome on Windows', $device->label, 'the label is derived from the User-Agent');
        $this->assertNull($device->revoked_at);
        $this->assertTrue($device->expires_at->greaterThan(now()->addHours(23)));

        $raw = $this->rawCookieFrom($res);
        $this->assertNotNull($raw, 'a trusted-device cookie must be queued on the redirect');
        [$selector, $validator] = explode(':', $raw, 2);
        $this->assertSame($device->selector, $selector);
        $this->assertSame($device->validator_hash, hash('sha256', $validator), 'only the SHA-256 of the validator is stored');
        $this->assertStringNotContainsString($validator, json_encode($device->toArray()), 'the raw validator is never persisted');
    }

    public function test_unticked_checkbox_issues_nothing(): void
    {
        [$user, $secret] = $this->enrolledUser();
        $this->login($user);

        $res = $this->post('/mfa/challenge', ['code' => $this->currentCode($secret)]);
        $res->assertRedirect(route('dashboard'));

        $this->assertSame(0, TrustedDevice::count(), 'no device row without an explicit opt-in');
        $this->assertNull($this->rawCookieFrom($res));
    }

    public function test_setting_zero_issues_nothing_even_when_ticked(): void
    {
        Setting::current()->update(['mfa_trusted_device_hours' => 0]);
        [$user, $secret] = $this->enrolledUser();
        $this->login($user);

        $res = $this->post('/mfa/challenge', ['code' => $this->currentCode($secret), 'trust_device' => true]);
        $res->assertRedirect(route('dashboard'));

        $this->assertSame(0, TrustedDevice::count(), 'the feature is off — no row may be minted');
        $this->assertNull($this->rawCookieFrom($res));
    }

    // ---------------------------------------------------------------- recovery codes
    //
    // The challenge accepts a TOTP code OR a recovery code in the same field. A recovery code is a
    // SINGLE-USE break-glass credential — printed, kept near ward terminals, never expiring. Letting
    // one mint a 24-hour trusted device would convert it into a standing second-factor waiver held by
    // whoever found the printout, which is exactly the escalation mandatory MFA exists to prevent.

    public function test_a_recovery_code_login_cannot_grant_a_trusted_device(): void
    {
        [$user, , $plain] = $this->enrolledUser();

        $res = $this->login($user)->assertRedirect(route('mfa.challenge'));
        $res = $this->post('/mfa/challenge', ['code' => $plain[0], 'trust_device' => true]);

        $res->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);   // the login itself is fine — only the trust is refused
        $this->assertSame(0, TrustedDevice::where('user_id', $user->id)->count(),
            'a break-glass code must never mint a standing waiver of the factor it replaced');
        $this->assertNull($this->rawCookieFrom($res), 'and no cookie may be queued either');

        $log = AuditLog::where('action', 'login.success')->where('actor_id', $user->id)->latest('id')->firstOrFail();
        $this->assertSame(['mfa' => true, 'via' => 'recovery_code'], $log->details,
            'a second factor WAS presented, but the trail must say which one');
    }

    /** Refusing the tick must be VISIBLE — a silent refusal leaves the user expecting no next prompt. */
    public function test_a_refused_trust_tick_tells_the_user_why(): void
    {
        [$user, , $plain] = $this->enrolledUser();
        $this->login($user);

        $this->post('/mfa/challenge', ['code' => $plain[0], 'trust_device' => true])
            ->assertSessionHas('flash.type', 'error');
    }

    /**
     * Needing a backup code is the user-side signal that the authenticator is lost or compromised.
     * The admin-side equivalent (ControlController::resetMfa) already revokes everything; the
     * user-side signal must not be weaker.
     */
    public function test_a_recovery_code_login_revokes_existing_trusted_devices(): void
    {
        [$user, , $plain] = $this->enrolledUser();
        [$existing] = $this->deviceFor($user);

        $this->login($user);
        $this->post('/mfa/challenge', ['code' => $plain[0]])->assertRedirect(route('dashboard'));

        $this->assertNotNull($existing->fresh()->revoked_at,
            'reaching for a backup code means the factor is compromised — every waiver standing on it dies');
    }

    public function test_a_totp_login_records_the_factor_as_totp(): void
    {
        [$user, $secret] = $this->enrolledUser();
        $this->login($user);
        $this->post('/mfa/challenge', ['code' => $this->currentCode($secret)])->assertRedirect(route('dashboard'));

        $log = AuditLog::where('action', 'login.success')->where('actor_id', $user->id)->latest('id')->firstOrFail();
        $this->assertSame(['mfa' => true, 'via' => 'totp'], $log->details);
    }

    // ---------------------------------------------------------------- consuming

    public function test_valid_cookie_skips_the_challenge_and_audits_trusted_device(): void
    {
        [$user] = $this->enrolledUser();
        [$device, $raw] = $this->deviceFor($user);

        $this->withTrust($raw)->post('/login', ['username' => $user->username, 'password' => 'secret12345'])
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);

        $log = AuditLog::where('action', 'login.success')->where('actor_id', $user->id)->latest('id')->firstOrFail();
        $this->assertSame(['mfa' => false, 'trusted_device' => true], $log->details,
            'mfa must be recorded false — no second factor was presented — with trusted_device explaining why');

        $this->assertNotNull($device->fresh()->last_used_at, 'using a device stamps last_used_at');
    }

    /**
     * THE core promise: the trusted device waives the CODE, never the PASSWORD. A stolen cookie
     * without the password must not get past the credential check at all.
     */
    public function test_password_is_still_required_with_a_valid_trust_cookie(): void
    {
        [$user] = $this->enrolledUser();
        [, $raw] = $this->deviceFor($user);

        $this->withTrust($raw)->post('/login', ['username' => $user->username, 'password' => 'wrong-password'])
            ->assertSessionHasErrors('username');
        $this->assertGuest();
        $this->assertNull(TrustedDevice::first()->last_used_at, 'a failed password must never even reach the cookie');
    }

    /**
     * A cookie is a waiver for ONE account. Presenting user A's cookie while logging in as user B
     * must change nothing — it is never an identity hint.
     */
    public function test_cookie_issued_for_one_user_does_not_skip_mfa_for_another(): void
    {
        [$alice] = $this->enrolledUser();
        [$bob] = $this->enrolledUser();
        [, $aliceRaw] = $this->deviceFor($alice);

        $this->withTrust($aliceRaw)->post('/login', ['username' => $bob->username, 'password' => 'secret12345'])
            ->assertRedirect(route('mfa.challenge'));
        $this->assertGuest();
        $this->assertNull(TrustedDevice::first()->last_used_at, "another user's login must not touch Alice's device");
    }

    public function test_expired_cookie_falls_back_to_the_challenge(): void
    {
        [$user] = $this->enrolledUser();
        [, $raw] = $this->deviceFor($user, ['expires_at' => now()->subMinute()]);

        $this->withTrust($raw)->post('/login', ['username' => $user->username, 'password' => 'secret12345'])
            ->assertRedirect(route('mfa.challenge'));
        $this->assertGuest();
    }

    public function test_revoked_cookie_falls_back_to_the_challenge(): void
    {
        [$user] = $this->enrolledUser();
        [, $raw] = $this->deviceFor($user, ['revoked_at' => now()->subMinute()]);

        $this->withTrust($raw)->post('/login', ['username' => $user->username, 'password' => 'secret12345'])
            ->assertRedirect(route('mfa.challenge'));
        $this->assertGuest();
    }

    public function test_unknown_selector_falls_back_to_the_challenge(): void
    {
        [$user] = $this->enrolledUser();
        $this->deviceFor($user);

        $bogus = bin2hex(random_bytes(16)) . ':' . bin2hex(random_bytes(32));
        $this->withTrust($bogus)->post('/login', ['username' => $user->username, 'password' => 'secret12345'])
            ->assertRedirect(route('mfa.challenge'));
        $this->assertGuest();
    }

    public function test_tampered_validator_falls_back_to_the_challenge(): void
    {
        [$user] = $this->enrolledUser();
        [, $raw] = $this->deviceFor($user);
        [$selector] = explode(':', $raw, 2);

        $tampered = $selector . ':' . bin2hex(random_bytes(32));
        $this->withTrust($tampered)->post('/login', ['username' => $user->username, 'password' => 'secret12345'])
            ->assertRedirect(route('mfa.challenge'));
        $this->assertGuest();
    }

    public function test_malformed_cookie_is_ignored_without_error(): void
    {
        [$user] = $this->enrolledUser();
        $this->deviceFor($user);

        foreach (['', 'no-colon-here', ':', 'selector:'] as $junk) {
            $this->assertNull(TrustedDevice::resolve($junk, $user), "resolve() must tolerate: '{$junk}'");
        }
        $this->assertNull(TrustedDevice::resolve(null, $user));
    }

    public function test_setting_zero_ignores_a_previously_issued_cookie(): void
    {
        [$user] = $this->enrolledUser();
        [$device, $raw] = $this->deviceFor($user);
        Setting::current()->update(['mfa_trusted_device_hours' => 0]);

        $this->withTrust($raw)->post('/login', ['username' => $user->username, 'password' => 'secret12345'])
            ->assertRedirect(route('mfa.challenge'));
        $this->assertGuest();
        $this->assertNull($device->fresh()->last_used_at, 'with the feature off the cookie is never even resolved');
    }

    /** Fixed window, not sliding: using the device stamps last_used_at and nothing else. */
    public function test_using_a_trusted_device_does_not_extend_its_window(): void
    {
        [$user] = $this->enrolledUser();
        [$device, $raw] = $this->deviceFor($user, ['expires_at' => now()->addHours(2)]);
        $before = $device->fresh()->expires_at;

        $this->withTrust($raw)->post('/login', ['username' => $user->username, 'password' => 'secret12345'])
            ->assertRedirect(route('dashboard'));

        $this->assertTrue($before->equalTo($device->fresh()->expires_at), 'expires_at must never be extended by use');
    }

    // ---------------------------------------------------------------- model unit checks

    public function test_revoke_all_for_revokes_only_live_rows_of_that_user(): void
    {
        [$alice] = $this->enrolledUser();
        [$bob] = $this->enrolledUser();
        [$live] = $this->deviceFor($alice);
        [$already] = $this->deviceFor($alice, ['revoked_at' => now()->subDay()]);
        [$others] = $this->deviceFor($bob);
        $wasRevokedAt = $already->revoked_at;

        TrustedDevice::revokeAllFor($alice->id);

        $this->assertNotNull($live->fresh()->revoked_at);
        $this->assertTrue($wasRevokedAt->equalTo($already->fresh()->revoked_at), 'an existing revocation timestamp is not rewritten');
        $this->assertNull($others->fresh()->revoked_at, "another user's devices are untouched");
    }

    public function test_usable_scope_excludes_expired_and_revoked(): void
    {
        [$user] = $this->enrolledUser();
        [$live] = $this->deviceFor($user);
        $this->deviceFor($user, ['expires_at' => now()->subMinute()]);
        $this->deviceFor($user, ['revoked_at' => now()]);

        $this->assertSame([$live->id], TrustedDevice::usable()->pluck('id')->all());
    }

    // ---------------------------------------------------------------- revocation sites
    //
    // Trust is a standing waiver of the second factor. It must die wherever the credentials or the
    // second factor behind it change — otherwise a password reset (the recovery action a compromised
    // user takes) would leave the attacker's browser still skipping MFA. One test per site.

    private function seedSession(int $userId, string $id): void
    {
        DB::table('sessions')->insert([
            'id' => $id, 'user_id' => $userId, 'ip_address' => '10.0.0.1',
            'user_agent' => 'test', 'payload' => 'x', 'last_activity' => now()->getTimestamp(),
        ]);
    }

    public function test_changing_the_password_revokes_trusted_devices(): void
    {
        [$user] = $this->enrolledUser();
        [$device] = $this->deviceFor($user);

        $this->actingAs($user)->put('/profile/password', [
            'current_password' => 'secret12345',
            'password' => 'BrandNew123', 'password_confirmation' => 'BrandNew123',
        ])->assertSessionHasNoErrors();

        $this->assertNotNull($device->fresh()->revoked_at, 'a password change must kill every standing MFA waiver');
    }

    public function test_resetting_the_password_revokes_trusted_devices(): void
    {
        [$user] = $this->enrolledUser();
        $user->update(['email' => 'td-reset@example.test']);
        [$device] = $this->deviceFor($user);

        $this->post('/reset-password', [
            'token' => Password::createToken($user), 'email' => 'td-reset@example.test',
            'password' => 'BrandNew123', 'password_confirmation' => 'BrandNew123',
        ])->assertRedirect(route('login'));

        $this->assertNotNull($device->fresh()->revoked_at, 'a reset is a recovery action — trust must not survive it');
    }

    /**
     * The admin MFA reset used to touch NOTHING but the enrollment columns (pre-existing gap called
     * out in the design spec): resetting someone's second factor while their live sessions, recaller
     * and trusted devices all stayed valid. All three now die with it.
     */
    public function test_admin_mfa_reset_revokes_devices_purges_sessions_and_rotates_the_recaller(): void
    {
        [$user] = $this->enrolledUser();
        [$device] = $this->deviceFor($user);
        $user->forceFill(['remember_token' => 'stale-recaller-token'])->save();
        $this->seedSession($user->id, 'td_stolen_session');

        $admin = User::create([
            'username' => 'tdadmin_' . substr(md5(uniqid('', true)), 0, 8),
            'name' => 'TD Admin', 'password' => 'secret12345', 'role' => User::ROLE_ADMIN, 'active' => 1,
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
            'email_verified_at' => now(), 'pass_exp_date' => now()->toDateString(),
        ]);

        $this->actingAs($admin)->post("/control/users/{$user->id}/reset-mfa")
            ->assertRedirect()->assertSessionHas('flash.type', 'success');

        $this->assertNotNull($device->fresh()->revoked_at, 'trusted devices must not outlive the second factor they waive');
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count(),
            'a live session must not survive an admin MFA reset');
        $this->assertNotSame('stale-recaller-token', $user->fresh()->remember_token, 'the recaller must be rotated');
        $this->assertDatabaseHas('audit_log', ['action' => 'user.reset_mfa', 'entity_id' => (string) $user->id]);
    }

    public function test_re_enrolling_mfa_revokes_trusted_devices(): void
    {
        [$user] = $this->enrolledUser();
        [$device] = $this->deviceFor($user);
        // confirm() only runs for a non-enrolled user — mimic the post-admin-reset state
        $user->forceFill(['mfa_secret' => null, 'mfa_recovery_codes' => null, 'mfa_enrolled_at' => null])->save();

        $secret = Totp::secret();
        $this->actingAs($user->fresh())
            ->withSession(['mfa.setup.secret' => $secret, 'mfa.setup.codes' => ['CCCC-DDDD']])
            ->post('/mfa/confirm', ['code' => $this->currentCode($secret)])
            ->assertSessionHasNoErrors();

        $this->assertNotNull($user->fresh()->mfa_enrolled_at, 'sanity: re-enrolment succeeded');
        $this->assertNotNull($device->fresh()->revoked_at, 're-enrolling a new second factor retires the old waivers');
    }

    /**
     * Deactivation is not revocation. AuthController::login filters on active=1, so a deactivated
     * user's waiver is merely DORMANT — reactivating the account inside the window would silently
     * restore a second-factor skip on a browser the admin believes they cut off.
     */
    public function test_deactivating_a_user_revokes_their_trusted_devices(): void
    {
        [$user] = $this->enrolledUser();
        [$device] = $this->deviceFor($user);

        $this->actingAs($this->adminUser())
            ->put("/control/users/{$user->id}", $this->userPayload($user, ['active' => false]))
            ->assertSessionHasNoErrors();

        $this->assertSame(0, (int) $user->fresh()->active, 'sanity: the account really was deactivated');
        $this->assertNotNull($device->fresh()->revoked_at,
            'a deactivated account must not keep a waiver that returns with it');
    }

    public function test_reactivating_a_user_does_not_resurrect_the_waiver(): void
    {
        [$user] = $this->enrolledUser();
        [$device] = $this->deviceFor($user);
        $admin = $this->adminUser();

        $this->actingAs($admin)->put("/control/users/{$user->id}", $this->userPayload($user, ['active' => false]));
        $this->actingAs($admin)->put("/control/users/{$user->id}", $this->userPayload($user->fresh(), ['active' => true]));

        $this->assertNotNull($device->fresh()->revoked_at, 'revocation is permanent — it is not an active flag');
    }

    public function test_soft_deleting_a_user_revokes_their_trusted_devices(): void
    {
        [$user] = $this->enrolledUser();
        [$device] = $this->deviceFor($user);

        $this->actingAs($this->adminUser())
            ->withSession(['stepup.verified_at' => now()->getTimestamp()])
            ->delete("/control/users/{$user->id}")
            ->assertSessionHasNoErrors();

        $this->assertSoftDeleted('users', ['id' => $user->id]);
        $this->assertNotNull($device->fresh()->revoked_at,
            'a deleted account is restorable from /trashed — its waivers must not come back with it');
    }

    /**
     * A role change is a CAPABILITY change, not a credential change — the password and the second
     * factor behind the waiver are both untouched, so trust legitimately survives it. Pinned so a
     * later "revoke on any user edit" broadening is a deliberate decision, not a drift.
     */
    public function test_changing_a_users_role_leaves_trusted_devices_alone(): void
    {
        [$user] = $this->enrolledUser();
        [$device] = $this->deviceFor($user);

        $this->actingAs($this->adminUser())
            ->put("/control/users/{$user->id}", $this->userPayload($user, ['role' => User::ROLE_RESIDENT]))
            ->assertSessionHasNoErrors();

        $this->assertNull($device->fresh()->revoked_at);
    }

    // ---------------------------------------------------------------- self-service revoke

    public function test_profile_lists_only_the_users_own_usable_devices(): void
    {
        [$user] = $this->enrolledUser();
        [$bob] = $this->enrolledUser();
        [$live] = $this->deviceFor($user, ['label' => 'Firefox on Linux']);
        $this->deviceFor($user, ['expires_at' => now()->subMinute()]);
        $this->deviceFor($user, ['revoked_at' => now()]);
        $this->deviceFor($bob, ['label' => "Bob's phone"]);

        $this->actingAs($user)->get('/profile')
            ->assertInertia(fn ($page) => $page
                ->has('trustedDevices', 1)
                ->where('trustedDevices.0.id', $live->id)
                ->where('trustedDevices.0.label', 'Firefox on Linux'));
    }

    public function test_a_user_can_revoke_one_of_their_own_devices(): void
    {
        [$user] = $this->enrolledUser();
        [$one] = $this->deviceFor($user);
        [$other] = $this->deviceFor($user);

        $this->actingAs($user)->delete("/profile/trusted-devices/{$one->id}")->assertRedirect();

        $this->assertNotNull($one->fresh()->revoked_at);
        $this->assertNull($other->fresh()->revoked_at, 'revoking one device leaves the others alone');
        $this->assertNotNull(TrustedDevice::find($one->id), 'revoked, never deleted — the trail survives');
    }

    /**
     * Object-level authorization. The posted id is never trusted: the query is scoped to the
     * authenticated user, so Bob asking to revoke Alice's device finds nothing and changes nothing.
     */
    public function test_a_user_cannot_revoke_another_users_device(): void
    {
        [$alice] = $this->enrolledUser();
        [$bob] = $this->enrolledUser();
        [$aliceDevice] = $this->deviceFor($alice);

        $this->actingAs($bob)->delete("/profile/trusted-devices/{$aliceDevice->id}")->assertRedirect();

        $this->assertNull($aliceDevice->fresh()->revoked_at, "another user's device must be untouchable");
    }

    public function test_a_user_can_revoke_all_of_their_own_devices(): void
    {
        [$alice] = $this->enrolledUser();
        [$bob] = $this->enrolledUser();
        [$a1] = $this->deviceFor($alice);
        [$a2] = $this->deviceFor($alice);
        [$bobDevice] = $this->deviceFor($bob);

        $this->actingAs($alice)->delete('/profile/trusted-devices')->assertRedirect();

        $this->assertNotNull($a1->fresh()->revoked_at);
        $this->assertNotNull($a2->fresh()->revoked_at);
        $this->assertNull($bobDevice->fresh()->revoked_at, "revoke-all is scoped to the caller");
    }

    public function test_trusted_device_routes_require_authentication(): void
    {
        [$user] = $this->enrolledUser();
        [$device] = $this->deviceFor($user);

        $this->delete("/profile/trusted-devices/{$device->id}")->assertRedirect(route('login'));
        $this->delete('/profile/trusted-devices')->assertRedirect(route('login'));
        $this->assertNull($device->fresh()->revoked_at);
    }

    /**
     * The {device} segment is bound to an int-typed controller argument, so an unconstrained route
     * hands "abc" to a TypeError — a 500 where a 404 belongs. Garbage in a URL is a not-found, and
     * a stack trace is never the right answer to it.
     */
    public function test_a_non_numeric_device_segment_is_a_404(): void
    {
        [$user] = $this->enrolledUser();

        $this->actingAs($user)->delete('/profile/trusted-devices/abc')->assertNotFound();
    }

    // ---------------------------------------------------------------- audit trail
    //
    // Granting a second-factor waiver and revoking one are both security-relevant acts, and this
    // system's posture is that every such act is answerable from the append-only audit_log.

    public function test_issuing_a_trusted_device_is_audited(): void
    {
        [$user, $secret] = $this->enrolledUser();
        $this->login($user);
        $this->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0) Chrome/120.0 Safari/537.36')
            ->post('/mfa/challenge', ['code' => $this->currentCode($secret), 'trust_device' => true]);

        $device = TrustedDevice::where('user_id', $user->id)->firstOrFail();
        $log = AuditLog::where('action', 'mfa.device_trusted')->where('actor_id', $user->id)->latest('id')->firstOrFail();

        $this->assertSame($device->id, $log->details['device_id']);
        $this->assertSame('Chrome on Windows', $log->details['label']);
        $this->assertArrayHasKey('expires_at', $log->details);

        $encoded = json_encode($log->details);
        $this->assertStringNotContainsString($device->selector, $encoded, 'the selector is credential material');
        $this->assertStringNotContainsString($device->validator_hash, $encoded, 'and so is the validator hash');
    }

    public function test_revoking_one_device_is_audited(): void
    {
        [$user] = $this->enrolledUser();
        [$device] = $this->deviceFor($user);

        $this->actingAs($user)->delete("/profile/trusted-devices/{$device->id}");

        $log = AuditLog::where('action', 'mfa.device_revoked')->where('actor_id', $user->id)->latest('id')->firstOrFail();
        $this->assertSame($device->id, $log->details['device_id']);
        $this->assertStringNotContainsString($device->selector, json_encode($log->details));
    }

    public function test_revoking_all_devices_is_audited_with_a_count(): void
    {
        [$user] = $this->enrolledUser();
        $this->deviceFor($user);
        $this->deviceFor($user);
        $this->deviceFor($user, ['revoked_at' => now()]);   // already dead — not part of the count

        $this->actingAs($user)->delete('/profile/trusted-devices');

        $log = AuditLog::where('action', 'mfa.device_revoked')->where('actor_id', $user->id)->latest('id')->firstOrFail();
        $this->assertSame(2, $log->details['count'], 'only the devices this action actually revoked');
    }

    /** Revoking nothing is not an event — an empty list must not litter the trail. */
    public function test_revoke_all_with_no_devices_writes_no_audit_row(): void
    {
        [$user] = $this->enrolledUser();

        $this->actingAs($user)->delete('/profile/trusted-devices');

        $this->assertSame(0, AuditLog::where('action', 'mfa.device_revoked')->count());
    }

    // ---------------------------------------------------------------- challenge prop + admin setting

    public function test_the_challenge_page_carries_the_window_only_when_the_feature_is_on(): void
    {
        [$user] = $this->enrolledUser();
        Setting::current()->update(['mfa_trusted_device_hours' => 12]);

        $this->login($user);
        $this->get('/mfa/challenge')->assertInertia(fn ($page) => $page->where('trustedDeviceHours', 12));

        Setting::current()->update(['mfa_trusted_device_hours' => 0]);
        $this->get('/mfa/challenge')->assertInertia(fn ($page) => $page->where('trustedDeviceHours', 0));
    }

    public function test_an_admin_can_save_the_trusted_device_window(): void
    {
        $admin = User::create([
            'username' => 'tdset_' . substr(md5(uniqid('', true)), 0, 8),
            'name' => 'TD Admin', 'password' => 'secret12345', 'role' => User::ROLE_ADMIN, 'active' => 1,
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
            'email_verified_at' => now(), 'pass_exp_date' => now()->toDateString(),
        ]);
        $s = Setting::current();

        $payload = array_merge($s->only([
            'min_hospitalist', 'max_hospitalist', 'min_subs', 'max_subs', 'short_los', 'long_los',
            'ward_beds', 'icu_beds', 'readmission_window_days', 'mfa_enforcement',
            'idle_timeout_minutes', 'abs_timeout_minutes', 'failed_login_notify_threshold',
            'dq_los_multiplier', 'alert_overcensus_pct', 'alert_boarding_max',
            'alert_readmit_rate_pct', 'alert_deaths_delta_pct',
        ]), ['mfa_trusted_device_hours' => 48]);

        $this->actingAs($admin)->put('/control/settings', $payload)->assertSessionHasNoErrors();

        $this->assertSame(48, (int) Setting::current()->mfa_trusted_device_hours);
    }
}
