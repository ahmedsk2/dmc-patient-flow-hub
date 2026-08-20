<?php

namespace Tests\Feature;

use App\Http\Controllers\Auth\UsernameReminderController;
use App\Mail\UsernameReminderMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * "Forgot username" reminder — mirrors the forgot-password anti-enumeration pattern: the response
 * is the SAME generic flash whether the email matches an active account or not, and only a match
 * actually sends mail / writes an audit row.
 */
class UsernameReminderTest extends TestCase
{
    use RefreshDatabase;

    private function activeUser(array $extra = []): User
    {
        return User::create(array_merge([
            'username' => 'ur_' . substr(md5(uniqid('', true)), 0, 8),
            'name' => 'Reminder User',
            'email' => 'ur.user@example.test',
            'password' => 'secret12345',
            'role' => User::ROLE_CONSULTANT,
            'active' => 1,
        ], $extra));
    }

    public function test_known_active_email_sends_mail_with_username_and_audits(): void
    {
        Mail::fake();
        $user = $this->activeUser();

        $response = $this->post('/forgot-username', ['email' => $user->email]);

        $response->assertRedirect();
        Mail::assertSent(UsernameReminderMail::class, function ($mail) use ($user) {
            return $mail->hasTo($user->email) && $mail->username === $user->username;
        });
        $this->assertDatabaseHas('audit_log', [
            'action' => 'username.reminder.sent',
            'actor_id' => $user->id,
            'entity_type' => 'user',
            'entity_id' => (string) $user->id,
        ]);
    }

    public function test_unknown_email_sends_no_mail_but_same_generic_response(): void
    {
        Mail::fake();

        $this->post('/forgot-username', ['email' => $this->activeUser()->email])
            ->assertRedirect()
            ->assertSessionHas('status', UsernameReminderController::GENERIC_MESSAGE);
        $this->post('/forgot-username', ['email' => 'nobody-' . uniqid() . '@example.test'])
            ->assertRedirect()
            ->assertSessionHas('status', UsernameReminderController::GENERIC_MESSAGE);

        Mail::assertSent(UsernameReminderMail::class, 1);
    }

    public function test_inactive_user_sends_no_mail_but_generic_response(): void
    {
        Mail::fake();
        $user = $this->activeUser(['active' => 0]);

        $this->post('/forgot-username', ['email' => $user->email])
            ->assertRedirect()
            ->assertSessionHas('status', UsernameReminderController::GENERIC_MESSAGE);

        Mail::assertNotSent(UsernameReminderMail::class);
        $this->assertDatabaseMissing('audit_log', ['action' => 'username.reminder.sent']);
    }

    public function test_unknown_and_inactive_produce_the_identical_flash_message(): void
    {
        Mail::fake();
        $inactive = $this->activeUser(['active' => 0]);

        $this->post('/forgot-username', ['email' => 'ghost-' . uniqid() . '@example.test'])
            ->assertSessionHas('status', UsernameReminderController::GENERIC_MESSAGE);
        $this->post('/forgot-username', ['email' => $inactive->email])
            ->assertSessionHas('status', UsernameReminderController::GENERIC_MESSAGE);
    }

    public function test_mail_failure_still_returns_generic_response_not_500(): void
    {
        // A transport failure only happens on the account-EXISTS branch; if it 500'd it would leak
        // which emails are registered. The controller swallows it and returns the uniform response.
        $user = $this->activeUser();
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('smtp down'));

        $this->post('/forgot-username', ['email' => $user->email])
            ->assertRedirect()
            ->assertSessionHas('status', UsernameReminderController::GENERIC_MESSAGE);

        // send threw before the audit write, so no 'sent' row is recorded
        $this->assertDatabaseMissing('audit_log', ['action' => 'username.reminder.sent']);
    }

    public function test_route_is_throttled(): void
    {
        $statuses = [];
        for ($i = 0; $i < 6; $i++) {
            $statuses[] = $this->post('/forgot-username', ['email' => 'throttle-target@example.test'])->getStatusCode();
        }
        // throttle:auth = 5/min → the 6th attempt within the minute is blocked
        $this->assertSame(429, $statuses[5], 'forgot-username should be throttled after 5 attempts');
    }
}
