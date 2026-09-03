<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Login accepts either the username OR the email in the same `username` POST field (field key is
 * unchanged — only the lookup now matches either identifier). Email matching is case-insensitive.
 */
class EmailOrUsernameLoginTest extends TestCase
{
    use RefreshDatabase;

    private function mfaUser(): User
    {
        return User::create([
            'username' => 'eu_'.substr(md5(uniqid('', true)), 0, 8),
            'name' => 'Email Or Username User',
            'email' => 'eu.user@example.test',
            'password' => 'secret12345',
            'role' => User::ROLE_CONSULTANT,
            'active' => 1,
            'mfa_secret' => Totp::secret(),
            'mfa_enrolled_at' => now(),
        ]);
    }

    public function test_mfa_enrolled_user_can_log_in_by_email(): void
    {
        $user = $this->mfaUser();

        $this->post('/login', ['username' => $user->email, 'password' => 'secret12345'])
            ->assertRedirect(route('mfa.challenge'))
            ->assertSessionHas('mfa.pending.id', $user->id);
    }

    public function test_same_user_still_logs_in_by_username(): void
    {
        $user = $this->mfaUser();

        $this->post('/login', ['username' => $user->username, 'password' => 'secret12345'])
            ->assertRedirect(route('mfa.challenge'))
            ->assertSessionHas('mfa.pending.id', $user->id);
    }

    public function test_email_match_is_case_insensitive(): void
    {
        $user = $this->mfaUser();

        $this->post('/login', ['username' => strtoupper($user->email), 'password' => 'secret12345'])
            ->assertRedirect(route('mfa.challenge'))
            ->assertSessionHas('mfa.pending.id', $user->id);
    }

    public function test_wrong_identifier_gives_generic_error_and_audits_submitted_string(): void
    {
        $this->post('/login', ['username' => 'nobody@example.test', 'password' => 'whatever'])
            ->assertSessionHasErrors('username');

        $this->assertGuest();
        $this->assertDatabaseHas('audit_log', [
            'action' => 'login.failed',
            'actor_name' => 'nobody@example.test',
        ]);
    }
}
