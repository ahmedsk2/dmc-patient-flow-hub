<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Password reset via Laravel's broker (replaces the legacy md5-token reset, which was
 * deterministic, non-expiring and SQL-injectable). Token round-trip, pass_exp_date restamp,
 * and invalid-token rejection.
 */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private function user(): User
    {
        return User::create([
            'username' => 'pr_'.substr(md5(uniqid('', true)), 0, 8),
            'name' => 'PR User', 'email' => 'pr.user@example.test',
            'password' => 'OldPass12345', 'role' => User::ROLE_CONSULTANT, 'active' => 1,
        ]);
    }

    public function test_reset_link_sent_and_token_resets_password(): void
    {
        Notification::fake();
        $user = $this->user();

        $this->post('/forgot-password', ['email' => $user->email])->assertRedirect();

        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function ($n) use (&$token) {
            $token = $n->token;

            return true;
        });
        $this->assertNotNull($token);

        $this->post('/reset-password', [
            'token' => $token, 'email' => $user->email,
            'password' => 'NewPass12345', 'password_confirmation' => 'NewPass12345',
        ])->assertRedirect(route('login'));

        $user->refresh();
        $this->assertTrue(Hash::check('NewPass12345', $user->password));
        $this->assertSame(now()->toDateString(), $user->pass_exp_date?->toDateString() ?? (string) $user->pass_exp_date,
            'reset must restamp pass_exp_date (3-month expiry clock)');
    }

    public function test_invalid_token_does_not_change_password(): void
    {
        $user = $this->user();

        $this->post('/reset-password', [
            'token' => 'bogus-token', 'email' => $user->email,
            'password' => 'NewPass12345', 'password_confirmation' => 'NewPass12345',
        ])->assertSessionHasErrors('email');

        $this->assertTrue(Hash::check('OldPass12345', $user->fresh()->password));
    }
}
