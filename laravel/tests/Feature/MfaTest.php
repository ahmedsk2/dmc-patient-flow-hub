<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Two-factor (TOTP) flow: a valid code authenticates, the SAME code cannot be replayed within its
 * window (#7 replay guard), and recovery codes are single-use.
 * Fixtures carry mfa.pending.at since H2/C3 — a pending challenge identity expires after 5 min.
 */
class MfaTest extends TestCase
{
    use RefreshDatabase;

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
            'username' => 'mfa_'.substr(md5(uniqid('', true)), 0, 8),
            'name' => 'Mfa User', 'password' => 'secret12345', 'role' => User::ROLE_CONSULTANT, 'active' => 1,
            'mfa_secret' => $secret,
            'mfa_recovery_codes' => array_map(fn ($c) => Hash::make($c), $plain),
            'mfa_enrolled_at' => now(),
        ]);

        return [$user, $secret, $plain];
    }

    public function test_valid_totp_authenticates_but_cannot_be_replayed(): void
    {
        [$user, $secret] = $this->enrolledUser();
        $code = $this->currentCode($secret);

        // first use: authenticates
        $this->withSession(['mfa.pending.id' => $user->id, 'mfa.pending.at' => now()->getTimestamp()])
            ->post('/mfa/challenge', ['code' => $code])
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);

        // replay the same code in a fresh challenge: rejected, not authenticated
        $this->post('/logout');
        $this->withSession(['mfa.pending.id' => $user->id, 'mfa.pending.at' => now()->getTimestamp()])
            ->post('/mfa/challenge', ['code' => $code])
            ->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    /**
     * K1-5: MFA logins are NEVER remembered — even when the password step was submitted with
     * remember=true, the challenge authenticates with Auth::login($user, false), so no recaller
     * cookie is queued and the user re-authenticates (password + code) every session.
     */
    public function test_mfa_login_is_never_remembered(): void
    {
        [$user, $secret] = $this->enrolledUser();

        // password step WITH remember — parks the pending identity, no auth yet
        $this->post('/login', ['username' => $user->username, 'password' => 'secret12345', 'remember' => true])
            ->assertRedirect(route('mfa.challenge'));
        $this->assertGuest();

        $res = $this->post('/mfa/challenge', ['code' => $this->currentCode($secret)]);
        $res->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);

        $recaller = collect($res->headers->getCookies())
            ->first(fn ($c) => str_starts_with($c->getName(), 'remember_web_') && $c->getValue());
        $this->assertNull($recaller, 'an MFA login must not queue a remember-me recaller cookie');
        $this->assertEmpty((string) $user->fresh()->remember_token, 'no remember token may be stored for an MFA login');
    }

    public function test_recovery_code_is_single_use(): void
    {
        [$user, , $plain] = $this->enrolledUser(['ZZZZ-9999']);

        $this->withSession(['mfa.pending.id' => $user->id, 'mfa.pending.at' => now()->getTimestamp()])
            ->post('/mfa/challenge', ['code' => $plain[0]])
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);

        $this->post('/logout');
        $this->withSession(['mfa.pending.id' => $user->id, 'mfa.pending.at' => now()->getTimestamp()])
            ->post('/mfa/challenge', ['code' => $plain[0]])
            ->assertSessionHasErrors('code');
        $this->assertGuest();
    }
}
