<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 4 — Item 3: login-anomaly auditing + admin Security panel + the failed-login-burst
 * notification. login.success / login.failed are written on every auth path; the Security panel is
 * admin-only and reads the audit log.
 */
class LoginAnomalyTest extends TestCase
{
    use RefreshDatabase;

    private function user(int $role = User::ROLE_ADMIN, array $extra = []): User
    {
        return User::create(array_merge([
            'username' => 'la_' . $role . '_' . substr(md5(uniqid('', true)), 0, 8),
            'name' => 'LA User', 'role' => $role, 'active' => 1, 'password' => 'secret12345',   // hashed cast
        ], $extra));
    }

    public function test_failed_login_writes_audit_row(): void
    {
        $this->post('/login', ['username' => 'ghost', 'password' => 'wrongpass'])
            ->assertSessionHasErrors('username');

        $this->assertDatabaseHas('audit_log', [
            'action' => 'login.failed', 'actor_name' => 'ghost', 'ip' => '127.0.0.1',
        ]);
    }

    public function test_successful_login_writes_audit_row(): void
    {
        $u = $this->user(User::ROLE_CONSULTANT);

        $this->post('/login', ['username' => $u->username, 'password' => 'secret12345'])->assertRedirect();

        $this->assertDatabaseHas('audit_log', [
            'action' => 'login.success', 'actor_id' => $u->id, 'entity_id' => (string) $u->id,
        ]);
    }

    public function test_mfa_failure_writes_audit_row(): void
    {
        $secret = \App\Support\Totp::secret();
        $u = $this->user(User::ROLE_ADMIN, ['mfa_secret' => $secret, 'mfa_enrolled_at' => now()]);

        $this->withSession([
            'mfa.pending.id' => $u->id, 'mfa.pending.at' => now()->getTimestamp(), 'mfa.pending.attempts' => 0,
        ])->post('/mfa/challenge', ['code' => '000000'])->assertSessionHasErrors('code');

        $this->assertDatabaseHas('audit_log', [
            'action' => 'login.failed', 'actor_id' => $u->id,
        ]);
    }

    public function test_notification_fired_at_threshold(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        Setting::current()->update(['failed_login_notify_threshold' => 3]);

        for ($i = 0; $i < 3; $i++) {
            $this->post('/login', ['username' => 'targetacct', 'password' => 'nope']);
        }

        $this->assertDatabaseHas('notifications', [
            'user_id' => $admin->id, 'type' => 'security.failed_logins',
        ]);
    }

    public function test_notification_not_fired_below_threshold(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);
        Setting::current()->update(['failed_login_notify_threshold' => 3]);

        for ($i = 0; $i < 2; $i++) {
            $this->post('/login', ['username' => 'targetacct', 'password' => 'nope']);
        }

        $this->assertDatabaseMissing('notifications', [
            'user_id' => $admin->id, 'type' => 'security.failed_logins',
        ]);
    }

    public function test_security_panel_accessible_to_admin(): void
    {
        $admin = $this->user(User::ROLE_ADMIN);

        $this->actingAs($admin)->get('/security')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Security/Index')
                ->has('failedClusters')->has('firstSeenIps')->has('mfaNonCompliant'));
    }

    public function test_security_panel_denied_to_non_admin(): void
    {
        $this->actingAs($this->user(User::ROLE_CONSULTANT))->get('/security')->assertForbidden();
    }
}
