<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * #233: logout and forced session-end (idle/absolute timeout) were not audited, so session
 * boundaries couldn't be reconstructed from audit_log alone (login.success/login.failed already
 * were — AuthController::login). This closes that gap: AuthController::logout writes a `logout`
 * row, and SessionTimeout writes a `session.timeout` row (with reason idle|absolute) BEFORE the
 * forced Auth::logout(), so the actor is still resolvable from the session at write time.
 */
class LogoutAuditTest extends TestCase
{
    use RefreshDatabase;

    // 'logout' is exempt from email.verify/mfa.enroll/pwd (see those middlewares), so a bare
    // active user is enough to reach AuthController::logout via the real route.
    private function user(array $extra = []): User
    {
        return User::create(array_merge([
            'username' => 'lo_' . substr(md5(uniqid('', true)), 0, 8),
            'name' => 'Logout User', 'password' => 'secret12345', 'role' => User::ROLE_ADMIN, 'active' => 1,
        ], $extra));
    }

    private function setTimeouts(int $idle, int $abs): void
    {
        Setting::current()->update(['idle_timeout_minutes' => $idle, 'abs_timeout_minutes' => $abs]);
    }

    public function test_logout_writes_one_audit_row_with_session_sourced_actor(): void
    {
        $user = $this->user();

        $this->actingAs($user)->post('/logout')->assertRedirect(route('login'));

        $rows = AuditLog::where('action', 'logout')->get();
        $this->assertCount(1, $rows);
        $row = $rows->first();
        $this->assertSame($user->id, $row->actor_id);
        $this->assertSame($user->name, $row->actor_name);
        $this->assertSame('user', $row->entity_type);
        $this->assertSame((string) $user->id, $row->entity_id);
    }

    public function test_guest_logout_request_writes_no_audit_row(): void
    {
        // no actingAs() — the 'auth' middleware bounces this before the controller ever runs.
        $this->post('/logout')->assertRedirect(route('login'));

        $this->assertSame(0, AuditLog::where('action', 'logout')->count());
    }

    public function test_idle_session_timeout_writes_session_timeout_row_with_idle_reason(): void
    {
        $this->setTimeouts(30, 0);
        $user = $this->user();

        $this->actingAs($user)
            ->withSession(['last_activity_at' => now()->subMinutes(31)->getTimestamp()])
            ->get('/patients')->assertRedirect('/login');

        $rows = AuditLog::where('action', 'session.timeout')->get();
        $this->assertCount(1, $rows);
        $row = $rows->first();
        $this->assertSame($user->id, $row->actor_id);
        $this->assertSame('user', $row->entity_type);
        $this->assertSame((string) $user->id, $row->entity_id);
        $this->assertSame(['reason' => 'idle'], $row->details);
    }

    public function test_absolute_session_timeout_writes_session_timeout_row_with_absolute_reason(): void
    {
        $this->setTimeouts(30, 60);
        $user = $this->user();

        $this->actingAs($user)
            ->withSession([
                'session_started_at' => now()->subMinutes(61)->getTimestamp(),
                'last_activity_at' => now()->subMinute()->getTimestamp(),
            ])
            ->get('/patients')->assertRedirect('/login');

        $rows = AuditLog::where('action', 'session.timeout')->get();
        $this->assertCount(1, $rows);
        $this->assertSame(['reason' => 'absolute'], $rows->first()->details);
    }

    public function test_session_within_timeout_windows_writes_no_session_timeout_row(): void
    {
        $this->setTimeouts(30, 0);
        $user = $this->user(['mfa_secret' => \App\Support\Totp::secret(), 'mfa_enrolled_at' => now()]);

        $this->actingAs($user)
            ->withSession(['last_activity_at' => now()->subMinutes(5)->getTimestamp()])
            ->get('/')->assertOk();

        $this->assertSame(0, AuditLog::where('action', 'session.timeout')->count());
    }
}
