<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 2026-09-23 walkthrough — MAJOR defect: deactivating a user (Control -> Users, active -> 0) or
 * deleting one (soft delete) blocked FUTURE logins (AuthController filters active=1) but did NOT
 * end their existing session — a deactivated/deleted user kept using the app until the session
 * expired on its own. Nothing in the auth chain re-checked `users.active` per request.
 *
 * Fix, both halves:
 *  (a) ControlController::updateUser / destroyUser now end every `sessions` row the user holds
 *      immediately (mirroring resetMfa's `DB::table('sessions')->where('user_id', ...)->delete()`),
 *      on top of the existing TrustedDevice revocation.
 *  (b) SessionTimeout (already on every authenticated route) now independently re-verifies the
 *      signed-in user is active and not soft-deleted on every request — defence in depth for any
 *      path that flips `active`/`deleted_at` outside this controller (a DB-level change, a file
 *      session, a future code path).
 */
class SessionEvictionOnDeactivateOrDeleteTest extends TestCase
{
    use RefreshDatabase;

    private function user(int $role = User::ROLE_CONSULTANT, array $extra = []): User
    {
        return User::create(array_merge([
            'username' => 'sev_'.substr(md5(uniqid('', true)), 0, 10),
            'name' => 'Session Eviction User', 'password' => 'secret12345', 'role' => $role, 'active' => 1,
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
        ], $extra));
    }

    private function admin(array $extra = []): User
    {
        return $this->user(User::ROLE_ADMIN, $extra);
    }

    private function seedSession(int $userId, string $id): void
    {
        DB::table('sessions')->insert([
            'id' => $id, 'user_id' => $userId, 'ip_address' => '10.0.0.1',
            'user_agent' => 'test', 'payload' => 'x', 'last_activity' => now()->getTimestamp(),
        ]);
    }

    /** The full payload ControlController::updateUser's validate() needs, `active` swapped in. */
    private function updatePayload(User $u, bool $active): array
    {
        return [
            'username' => $u->username, 'full_name' => $u->full_name, 'email' => $u->email,
            'role' => (int) $u->role, 'active' => $active, 'on_service' => (bool) $u->on_service,
            'specialty_id' => $u->specialty_id,
            'can_assign' => (bool) $u->can_assign, 'can_add' => (bool) $u->can_add,
            'can_manage' => (bool) $u->can_manage, 'can_modify' => (bool) $u->can_modify,
            'can_coordinate_consultations' => (bool) $u->can_coordinate_consultations,
        ];
    }

    // ---- (a) ControlController ends the session rows immediately ------------------------------

    public function test_deactivating_a_user_deletes_their_session_rows(): void
    {
        $target = $this->user();
        $this->seedSession($target->id, 'sess_deact_a');
        $this->seedSession($target->id, 'sess_deact_b');

        $this->actingAs($this->admin())
            ->put("/control/users/{$target->id}", $this->updatePayload($target, false))
            ->assertRedirect();

        $this->assertSame(0, DB::table('sessions')->where('user_id', $target->id)->count());
    }

    public function test_deleting_a_user_deletes_their_session_rows(): void
    {
        $target = $this->user();
        $this->seedSession($target->id, 'sess_del_a');

        $this->actingAs($this->admin())
            ->withSession(['stepup.verified_at' => now()->getTimestamp()])
            ->delete("/control/users/{$target->id}")
            ->assertRedirect();

        $this->assertSame(0, DB::table('sessions')->where('user_id', $target->id)->count());
        $this->assertTrue($target->fresh()->trashed());
    }

    public function test_deactivating_a_user_does_not_touch_other_users_sessions(): void
    {
        $target = $this->user();
        $other = $this->user();
        $admin = $this->admin();
        $this->seedSession($target->id, 'sess_touch_target');
        $this->seedSession($other->id, 'sess_touch_other');
        $this->seedSession($admin->id, 'sess_touch_admin');

        $this->actingAs($admin)
            ->put("/control/users/{$target->id}", $this->updatePayload($target, false))
            ->assertRedirect();

        $this->assertSame(0, DB::table('sessions')->where('user_id', $target->id)->count(),
            "the deactivated user's own sessions must be gone");
        $this->assertSame(1, DB::table('sessions')->where('user_id', $other->id)->count(),
            "an unrelated user's session must be untouched");
        $this->assertSame(1, DB::table('sessions')->where('user_id', $admin->id)->count(),
            "the acting admin's own session must be untouched");
    }

    public function test_deactivation_audit_row_records_sessions_ended_count(): void
    {
        $target = $this->user();
        $this->seedSession($target->id, 'sess_audit_a');
        $this->seedSession($target->id, 'sess_audit_b');

        $this->actingAs($this->admin())
            ->put("/control/users/{$target->id}", $this->updatePayload($target, false));

        $row = AuditLog::where('action', 'user.update')->where('entity_id', (string) $target->id)
            ->latest('id')->first();
        $this->assertNotNull($row);
        $this->assertSame(2, $row->details['sessions_ended'] ?? null);
    }

    // ---- (b) SessionTimeout re-checks active/soft-delete on every request ---------------------

    public function test_a_deactivated_users_next_request_is_redirected_to_login(): void
    {
        $target = $this->user();
        $target->update(['active' => false]);

        $this->actingAs($target)->get('/patients')
            ->assertRedirect('/login')
            ->assertSessionHas('flash.type', 'error');
    }

    public function test_a_deleted_users_next_request_is_redirected_to_login(): void
    {
        $target = $this->user();
        $target->delete();   // soft delete

        $this->actingAs($target)->get('/patients')
            ->assertRedirect('/login')
            ->assertSessionHas('flash.type', 'error');
    }

    /**
     * 2026-09-23 review fix — code-review finding: the `account_not_found` branch (the row is
     * genuinely gone, not merely soft-deleted — e.g. a stale `sessions` row surviving a
     * legacy:import truncate/rebuild, CLAUDE.md §10) used to audit BEFORE logout unconditionally.
     * Audit::log() reads Auth::id(), and audit_log.actor_id is a REAL FK to users.id
     * (nullOnDelete) — inserting a nonexistent id there throws a QueryException instead of
     * redirecting cleanly, leaving the session live. actingAs() + forceDelete() simulates the
     * guard already holding a user whose row is then hard-deleted out from under it — the one way
     * to reach this branch in a test, since Laravel's own `auth` middleware (which precedes
     * session.timeout in the route group) would otherwise short-circuit a from-scratch request for
     * a nonexistent/soft-deleted id before SessionTimeout ever ran.
     */
    public function test_a_session_pointing_at_a_user_row_that_no_longer_exists_gets_a_clean_redirect_not_a_crash(): void
    {
        $target = $this->user();
        $targetId = $target->id;
        $target->forceDelete();   // hard gone, not just soft-deleted

        $this->actingAs($target)->get('/patients')
            ->assertRedirect('/login')
            ->assertSessionHas('flash.type', 'error');

        $row = AuditLog::where('action', 'session.evicted')->where('entity_id', (string) $targetId)
            ->latest('id')->first();
        $this->assertNotNull($row);
        $this->assertSame('account_not_found', $row->details['reason'] ?? null);
        $this->assertNull($row->actor_id, 'actor_id must be null, not a dangling id that would violate the FK');
    }

    public function test_deactivated_user_cannot_load_a_page(): void
    {
        $target = $this->user();
        $target->update(['active' => false]);

        // a redirect response, not the 200 + board markup a live page load would return
        $this->actingAs($target)->get('/patients')->assertStatus(302);
    }

    public function test_an_active_users_normal_request_is_unaffected(): void
    {
        $u = $this->user();

        $this->actingAs($u)->get('/patients')->assertOk();
    }

    public function test_deactivation_evicts_a_request_logged_in_as_a_different_user_is_untouched(): void
    {
        // sanity: evicting X must never evict an unrelated already-logged-in Y
        $other = $this->user();
        $target = $this->user();
        $target->update(['active' => false]);

        $this->actingAs($other)->get('/patients')->assertOk();
    }

    // ---- reactivation ---------------------------------------------------------------------------

    public function test_after_reactivation_the_user_can_start_a_fresh_session(): void
    {
        $target = $this->user(User::ROLE_CONSULTANT, ['password' => 'FreshPass123']);
        $target->update(['active' => false]);

        // reactivate through the admin endpoint
        $this->actingAs($this->admin())
            ->put("/control/users/{$target->id}", $this->updatePayload($target->fresh(), true))
            ->assertRedirect();

        $this->assertTrue((bool) $target->fresh()->active);

        // actingAs() leaves the admin resolved on the shared guard for the rest of this test method
        // (it never touched the session, so a plain logout() is enough) — without clearing it, the
        // `guest` middleware in front of /login would see the admin as already authenticated and
        // redirect away before ever checking target's credentials.
        $this->app['auth']->guard('web')->logout();

        // a brand-new login now succeeds: password step reaches the (mandatory-MFA) challenge...
        $this->post('/login', ['username' => $target->username, 'password' => 'FreshPass123'])
            ->assertRedirect('/mfa/challenge');

        // ...and completing it establishes a real, working session (mirrors
        // SessionTimeoutTest::test_mfa_login_stamps_session_started_at).
        $secret = $target->mfa_secret;
        $reflect = new \ReflectionMethod(Totp::class, 'code');
        $reflect->setAccessible(true);
        $code = $reflect->invoke(null, $secret, intdiv(time(), 30));

        $this->withSession([
            'mfa.pending.id' => $target->id,
            'mfa.pending.at' => now()->getTimestamp(),
            'mfa.pending.attempts' => 0,
        ])->post('/mfa/challenge', ['code' => $code])->assertRedirect();

        $this->assertAuthenticatedAs($target->fresh());
        $this->get('/patients')->assertOk();
    }
}
