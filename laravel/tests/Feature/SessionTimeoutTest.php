<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 4 — Item 2: server-side idle + absolute session timeout. The SessionTimeout middleware runs
 * on every authenticated request; the client overlay is only a UX convenience.
 */
class SessionTimeoutTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::create([
            'username' => 'st_' . substr(md5(uniqid('', true)), 0, 8),
            'name' => 'ST User', 'password' => 'secret12345', 'role' => User::ROLE_ADMIN, 'active' => 1,
        ]);
    }

    private function setTimeouts(int $idle, int $abs): void
    {
        Setting::current()->update(['idle_timeout_minutes' => $idle, 'abs_timeout_minutes' => $abs]);
    }

    public function test_request_within_idle_window_succeeds(): void
    {
        $this->setTimeouts(30, 0);
        $this->actingAs($this->user())
            ->withSession(['last_activity_at' => now()->subMinutes(5)->getTimestamp()])
            ->get('/')->assertOk();
    }

    public function test_request_after_idle_timeout_is_redirected_to_login(): void
    {
        $this->setTimeouts(30, 0);
        $this->actingAs($this->user())
            ->withSession(['last_activity_at' => now()->subMinutes(31)->getTimestamp()])
            ->get('/patients')->assertRedirect('/login');
    }

    public function test_absolute_timeout_logs_out_even_with_recent_activity(): void
    {
        $this->setTimeouts(30, 60);
        $this->actingAs($this->user())
            ->withSession([
                'session_started_at' => now()->subMinutes(61)->getTimestamp(),
                'last_activity_at' => now()->subMinute()->getTimestamp(),
            ])
            ->get('/patients')->assertRedirect('/login');
    }

    public function test_absolute_timeout_zero_disables_absolute_limit(): void
    {
        $this->setTimeouts(30, 0);
        $this->actingAs($this->user())
            ->withSession([
                'session_started_at' => now()->subMinutes(999)->getTimestamp(),
                'last_activity_at' => now()->getTimestamp(),
            ])
            ->get('/patients')->assertOk();
    }

    public function test_login_stamps_session_started_at(): void
    {
        $u = $this->user();
        $u->update(['password' => 'secret12345']);   // ensure a known password (hashed cast)

        $this->post('/login', ['username' => $u->username, 'password' => 'secret12345'])
            ->assertRedirect();

        $this->assertNotNull(session('session_started_at'));
        $this->assertNotNull(session('last_activity_at'));
    }

    public function test_mfa_login_stamps_session_started_at(): void
    {
        // enroll a user with MFA so the login routes through the challenge
        $secret = \App\Support\Totp::secret();
        $u = User::create([
            'username' => 'st_mfa_' . substr(md5(uniqid('', true)), 0, 8),
            'name' => 'ST MFA', 'password' => 'secret12345', 'role' => User::ROLE_ADMIN, 'active' => 1,
            'mfa_secret' => $secret, 'mfa_enrolled_at' => now(),
        ]);

        // password step holds identity in the session
        $this->post('/login', ['username' => $u->username, 'password' => 'secret12345'])
            ->assertRedirect('/mfa/challenge');

        $this->withSession([
            'mfa.pending.id' => $u->id,
            'mfa.pending.at' => now()->getTimestamp(),
            'mfa.pending.attempts' => 0,
        ])->post('/mfa/challenge', ['code' => $this->totpFor($secret)])->assertRedirect();

        $this->assertNotNull(session('session_started_at'));
        $this->assertNotNull(session('last_activity_at'));
    }

    /** Current valid 6-digit TOTP for a secret, via the private Totp::code (verifier accepts it). */
    private function totpFor(string $secret): string
    {
        $reflect = new \ReflectionMethod(\App\Support\Totp::class, 'code');
        $reflect->setAccessible(true);

        return $reflect->invoke(null, $secret, intdiv(time(), 30));
    }
}
