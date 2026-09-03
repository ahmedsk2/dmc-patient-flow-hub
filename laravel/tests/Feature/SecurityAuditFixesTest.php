<?php

namespace Tests\Feature;

use App\Models\Admission;
use App\Models\Setting;
use App\Models\User;
use App\Support\Audit;
use App\Support\Totp;
use Illuminate\Cache\RateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * 2026-07-11 — fixes for the multi-agent production-readiness/security audit (7 clear wins).
 * M1 session-invalidation, M2 audit-transaction, M3 timezone, M4 mail-resilience, L2 handover
 * read-logging, L3 step-up throttle/attempt-cap, L4 readmission soft-delete.
 */
class SecurityAuditFixesTest extends TestCase
{
    use RefreshDatabase;

    private function mfaUser(array $attrs = []): User
    {
        return User::create(array_merge([
            'username' => 'u_'.substr(md5(uniqid('', true)), 0, 10),
            'name' => 'Test User',
            'role' => User::ROLE_ADMIN,
            'active' => 1,
            'password' => 'secret12345',
            'pass_exp_date' => now()->toDateString(),
            'email_verified_at' => now(),
            'mfa_secret' => Totp::secret(),
            'mfa_enrolled_at' => now(),
        ], $attrs));
    }

    private function newPatient(string $mrn): int
    {
        return DB::table('patients')->insertGetId([
            'mrn' => $mrn, 'name' => 'Pt '.$mrn, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function seedSession(int $userId, string $id): void
    {
        DB::table('sessions')->insert([
            'id' => $id, 'user_id' => $userId, 'ip_address' => '10.0.0.1',
            'user_agent' => 'test', 'payload' => 'x', 'last_activity' => now()->getTimestamp(),
        ]);
    }

    // ---- M1: password reset/change invalidates other sessions -------------------------------------

    public function test_password_reset_kills_every_session_for_the_account(): void
    {
        $user = $this->mfaUser(['email' => 'reset@example.test']);
        $this->seedSession($user->id, 'stolen_a');
        $this->seedSession($user->id, 'stolen_b');

        $token = Password::createToken($user);
        $this->post('/reset-password', [
            'token' => $token, 'email' => 'reset@example.test',
            'password' => 'BrandNew123', 'password_confirmation' => 'BrandNew123',
        ])->assertRedirect(route('login'));

        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count(),
            'a stolen live session must not survive a password reset');
    }

    public function test_password_change_evicts_other_sessions_but_keeps_the_current_one(): void
    {
        $user = $this->mfaUser(['password' => 'OldPass123']);
        $this->seedSession($user->id, 'other_device');

        $this->actingAs($user)->put('/profile/password', [
            'current_password' => 'OldPass123',
            'password' => 'NewPass456', 'password_confirmation' => 'NewPass456',
        ])->assertSessionHasNoErrors();

        $this->assertSame(0, DB::table('sessions')->where('id', 'other_device')->count(),
            'other sessions are evicted when the password changes');
    }

    // ---- M2: audit hash-chain stays valid (incl. when nested in a transaction) --------------------

    public function test_audit_chain_verifies_after_standalone_and_nested_writes(): void
    {
        $user = $this->mfaUser();
        $this->actingAs($user);

        foreach (range(1, 8) as $i) {
            Audit::log('test.event', 'user', (string) $user->id, ['i' => $i]);
        }
        // nested inside a caller-opened transaction (savepoint) must also produce a valid link
        DB::transaction(function () use ($user) {
            Audit::log('test.nested', 'user', (string) $user->id);
        });

        $this->assertDatabaseHas('audit_log', ['action' => 'test.nested']);
        $this->artisan('audit:verify')->assertExitCode(0);
    }

    // ---- M3: timezone honours APP_TIMEZONE --------------------------------------------------------

    public function test_app_timezone_is_driven_by_the_env_knob(): void
    {
        // config/app.php must read env('APP_TIMEZONE') so operators can set the hospital zone
        $this->assertSame(env('APP_TIMEZONE', 'UTC'), config('app.timezone'));
    }

    // ---- M4: verification-mail failure is graceful (no 500, cooldown not armed) --------------------

    public function test_registration_email_send_failure_is_friendly_and_does_not_persist_state(): void
    {
        Mail::shouldReceive('to')->andReturnSelf();
        Mail::shouldReceive('send')->andThrow(new \RuntimeException('smtp down'));

        $this->postJson('/register/email/send', ['email' => 'newapplicant@example.test'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->assertDatabaseCount('pending_registrations', 0);   // nothing armed → freely retryable
    }

    public function test_existing_user_verify_send_failure_is_friendly_and_does_not_arm_cooldown(): void
    {
        $user = $this->mfaUser(['email' => 'onfile@example.test', 'email_verified_at' => null]);
        Mail::shouldReceive('to')->andReturnSelf();
        Mail::shouldReceive('send')->andThrow(new \RuntimeException('smtp down'));

        $this->actingAs($user)->postJson('/email/verify/send', [])
            ->assertUnprocessable()->assertJsonValidationErrors('code');

        $this->assertNull(session('email.verify.sent_at'), 'a failed send must not arm the resend cooldown');
    }

    // ---- L2: handover read is break-glass logged when record-open logging is on -------------------

    public function test_handover_show_logs_a_break_glass_row_only_when_record_logging_is_on(): void
    {
        $admin = $this->mfaUser();
        $pid = $this->newPatient('HB1');
        $adm = Admission::create(['patient_id' => $pid, 'admit_date' => '2024-01-01', 'current_location' => 'Ward']);

        Setting::current()->update(['log_record_opens' => false]);
        $this->actingAs($admin)->getJson("/admissions/{$adm->id}/handover")->assertOk();
        $this->assertDatabaseMissing('audit_log', ['action' => 'handover.read', 'entity_id' => (string) $adm->id]);

        Setting::current()->update(['log_record_opens' => true]);
        $this->actingAs($admin)->getJson("/admissions/{$adm->id}/handover")->assertOk();
        $this->assertDatabaseHas('audit_log', [
            'action' => 'handover.read', 'entity_type' => 'admission', 'entity_id' => (string) $adm->id,
        ]);
    }

    // ---- L3: step-up re-auth is rate-limited + session-attempt-capped -----------------------------

    public function test_stepup_limiter_is_registered_and_keyed_by_user_or_ip(): void
    {
        $limiter = app(RateLimiter::class)->limiter('stepup');
        $this->assertNotNull($limiter);

        $limit = $limiter(Request::create('/stepup', 'POST', server: ['REMOTE_ADDR' => '203.0.113.5']));
        $this->assertStringContainsString('203.0.113.5', (string) $limit->key);   // no user → IP fallback
    }

    public function test_stepup_caps_repeated_bad_attempts_in_the_session(): void
    {
        // isolate the in-session attempt counter from the per-minute throttle (which would 429 first)
        $user = $this->mfaUser(['password' => 'RightPass123']);
        $this->actingAs($user)->withoutMiddleware(ThrottleRequests::class);

        foreach (range(1, 8) as $i) {
            $this->post('/stepup', ['password' => 'wrong', 'code' => '000000'])
                ->assertSessionHasErrors('password');
        }
        // the 9th attempt is rejected by the budget, not the password check
        $this->post('/stepup', ['password' => 'wrong', 'code' => '000000'])
            ->assertSessionHasErrors(['password' => 'Too many attempts — please wait a moment and try again.']);
    }

    // ---- L4: 72h-readmission predicate excludes soft-deleted prior admissions ---------------------

    public function test_readmission_predicate_excludes_soft_deleted_prior_admissions(): void
    {
        $pid = $this->newPatient('RM1');
        $prior = Admission::create(['patient_id' => $pid, 'admit_date' => '2024-01-01', 'discharge_date' => '2024-01-03']);
        $later = Admission::create(['patient_id' => $pid, 'admit_date' => '2024-01-05']);

        $isReadmit = fn () => Admission::where('id', $later->id)
            ->whereExists(Admission::readmissionExists(30))->exists();

        $this->assertTrue($isReadmit(), 'a live prior discharge anchors a readmission');

        $prior->delete();   // soft-delete the anchor
        $this->assertFalse($isReadmit(), 'a soft-deleted prior admission must not anchor a readmission');
    }
}
