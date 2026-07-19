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
use Illuminate\Support\Facades\Hash;
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
}
