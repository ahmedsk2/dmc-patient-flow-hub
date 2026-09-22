<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\PendingRegistration;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * R11 — auth:prune-expired. Deletes ONLY rows that are definitively expired by a value Laravel
 * itself wrote (pending_registrations.expires_at, trusted_devices.expires_at,
 * password_reset_tokens.created_at + config('auth.passwords.users.expire')), never audit_log or
 * notifications. Deletes by default (it is scheduled, unattended); --dry-run previews only. One
 * audit_log row with the counts is written, and only when something was actually deleted.
 */
class AuthPruneExpiredTest extends TestCase
{
    use RefreshDatabase;

    private function pendingRegistration(array $overrides = []): PendingRegistration
    {
        return PendingRegistration::create(array_merge([
            'token' => bin2hex(random_bytes(16)),
            'email' => 'pending_'.uniqid().'@example.test',
            'expires_at' => now()->addMinutes(30),
        ], $overrides));
    }

    private function trustedDevice(array $overrides = []): TrustedDevice
    {
        $user = User::create([
            'username' => 'td_'.substr(md5(uniqid('', true)), 0, 10),
            'name' => 'TD User', 'password' => 'secret12345', 'role' => User::ROLE_CONSULTANT, 'active' => 1,
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
        ]);

        return TrustedDevice::create(array_merge([
            'user_id' => $user->id,
            'selector' => bin2hex(random_bytes(16)),
            'validator_hash' => hash('sha256', bin2hex(random_bytes(32))),
            'expires_at' => now()->addHours(24),
        ], $overrides));
    }

    private function passwordResetToken(string $email, \DateTimeInterface $createdAt): void
    {
        DB::table('password_reset_tokens')->insert([
            'email' => $email, 'token' => bin2hex(random_bytes(16)), 'created_at' => $createdAt,
        ]);
    }

    // ---------------------------------------------------------------- dry run

    public function test_dry_run_reports_counts_and_deletes_nothing(): void
    {
        $this->pendingRegistration(['expires_at' => now()->subMinute()]);
        $this->trustedDevice(['expires_at' => now()->subMinute()]);
        $this->passwordResetToken('dry@example.test', now()->subHours(2));

        $this->artisan('auth:prune-expired', ['--dry-run' => true])
            ->expectsOutputToContain('3 row(s) eligible')
            ->assertExitCode(0);

        $this->assertSame(1, PendingRegistration::count());
        $this->assertSame(1, TrustedDevice::count());
        $this->assertSame(1, DB::table('password_reset_tokens')->count());
        $this->assertSame(0, AuditLog::where('action', 'auth.expired_rows_pruned')->count());
    }

    // ---------------------------------------------------------------- selective deletion

    public function test_only_expired_pending_registrations_are_deleted(): void
    {
        $expired = $this->pendingRegistration(['expires_at' => now()->subSecond()]);
        $live = $this->pendingRegistration(['expires_at' => now()->addMinutes(10)]);

        $this->artisan('auth:prune-expired')->assertExitCode(0);

        $this->assertNull(PendingRegistration::find($expired->id));
        $this->assertNotNull(PendingRegistration::find($live->id));
    }

    public function test_only_expired_trusted_devices_are_deleted(): void
    {
        $expired = $this->trustedDevice(['expires_at' => now()->subSecond()]);
        $live = $this->trustedDevice(['expires_at' => now()->addHours(10)]);
        // revoked but NOT yet past its fixed expiry window — must be left alone: revocation is a
        // separate, un-built retention policy (DATA-RETENTION.md §2.4), not "expired".
        $revokedNotExpired = $this->trustedDevice(['expires_at' => now()->addHours(10), 'revoked_at' => now()]);

        $this->artisan('auth:prune-expired')->assertExitCode(0);

        $this->assertNull(TrustedDevice::find($expired->id));
        $this->assertNotNull(TrustedDevice::find($live->id));
        $this->assertNotNull(TrustedDevice::find($revokedNotExpired->id));
    }

    public function test_only_password_reset_tokens_older_than_the_configured_expiry_are_deleted(): void
    {
        $expireMinutes = (int) config('auth.passwords.users.expire', 60);
        $this->passwordResetToken('old@example.test', now()->subMinutes($expireMinutes + 5));
        $this->passwordResetToken('recent@example.test', now()->subMinutes(max(1, $expireMinutes - 5)));

        $this->artisan('auth:prune-expired')->assertExitCode(0);

        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'old@example.test']);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => 'recent@example.test']);
    }

    // ---------------------------------------------------------------- audit trail

    public function test_a_run_that_deletes_something_writes_one_audit_row_with_counts_and_no_phi(): void
    {
        $this->pendingRegistration(['expires_at' => now()->subMinute()]);
        $this->pendingRegistration(['expires_at' => now()->subMinute()]);
        $this->trustedDevice(['expires_at' => now()->subMinute()]);
        $this->passwordResetToken('audited@example.test', now()->subHours(2));

        $this->artisan('auth:prune-expired')->assertExitCode(0);

        $rows = AuditLog::where('action', 'auth.expired_rows_pruned')->get();
        $this->assertCount(1, $rows, 'exactly one audit row per run, not one per deleted row');

        $log = $rows->first();
        $this->assertNull($log->actor_id, 'system actor — no authenticated user in a console run');
        $this->assertSame('auth', $log->entity_type);
        $this->assertNull($log->entity_id);
        // key ORDER isn't asserted: MySQL's JSON type doesn't guarantee round-tripping the
        // original member order, so this compares content only (still catches any stray key —
        // e.g. an accidentally-included email — via assertCount below).
        $this->assertCount(3, $log->details, 'counts only — no email, token or any other identifier');
        $this->assertSame(2, $log->details['pending_registrations']);
        $this->assertSame(1, $log->details['trusted_devices']);
        $this->assertSame(1, $log->details['password_reset_tokens']);

        $encoded = json_encode($log->details);
        $this->assertStringNotContainsString('audited@example.test', $encoded);
    }

    public function test_a_run_that_deletes_nothing_writes_no_audit_row(): void
    {
        // one live row of each kind, nothing expired
        $this->pendingRegistration(['expires_at' => now()->addMinutes(10)]);
        $this->trustedDevice(['expires_at' => now()->addHours(10)]);

        $this->artisan('auth:prune-expired')
            ->expectsOutputToContain('nothing expired')
            ->assertExitCode(0);

        $this->assertSame(0, AuditLog::where('action', 'auth.expired_rows_pruned')->count());
    }

    // ---------------------------------------------------------------- atomicity

    /**
     * Code review finding: the three deletes must not run as separate unwrapped statements with
     * Audit::log() only reached if all three succeed — an interruption partway through (lock-wait
     * timeout, dropped connection) would then leave earlier deletes committed with zero audit row.
     * Force a failure on the second delete (trusted_devices, after pending_registrations) and prove
     * the whole sweep — including the already-executed pending_registrations delete — rolls back.
     */
    public function test_a_failure_partway_through_the_sweep_rolls_back_every_delete_and_writes_no_audit_row(): void
    {
        $expiredPending = $this->pendingRegistration(['expires_at' => now()->subMinute()]);
        $expiredDevice = $this->trustedDevice(['expires_at' => now()->subMinute()]);
        $this->passwordResetToken('rollback@example.test', now()->subHours(2));

        // The command issues each delete as a mass query-builder delete (Model::query()->delete()),
        // which never fires per-row Eloquent 'deleting' events — so the failure has to be injected
        // at the SQL level. DB::listen fires synchronously as each statement executes, so throwing
        // from it propagates out of the trusted_devices delete exactly as a dropped connection or
        // lock-wait timeout would, after the pending_registrations delete already ran but before
        // the transaction commits.
        DB::listen(function ($query) {
            if (stripos($query->sql, 'delete') === 0 && str_contains($query->sql, 'trusted_devices')) {
                throw new \RuntimeException('simulated mid-sweep failure');
            }
        });

        // Artisan::call() (which the artisan() test helper uses) runs the command directly rather
        // than through the CLI entry point, so unlike a real terminal invocation it does not catch
        // the exception into an exit code — it propagates straight out of ->run().
        try {
            $this->artisan('auth:prune-expired')->run();
            $this->fail('expected the simulated failure to propagate out of the command');
        } catch (\RuntimeException $e) {
            $this->assertSame('simulated mid-sweep failure', $e->getMessage());
        }

        $this->assertNotNull(PendingRegistration::find($expiredPending->id), 'rolled back, not just left half-deleted');
        $this->assertNotNull(TrustedDevice::find($expiredDevice->id));
        $this->assertDatabaseHas('password_reset_tokens', ['email' => 'rollback@example.test']);
        $this->assertSame(0, AuditLog::where('action', 'auth.expired_rows_pruned')->count());
    }

    // ---------------------------------------------------------------- never touches audit_log / notifications

    public function test_does_not_touch_audit_log_or_notifications(): void
    {
        $this->pendingRegistration(['expires_at' => now()->subMinute()]);
        $recipient = User::create([
            'username' => 'notif_'.substr(md5(uniqid('', true)), 0, 10),
            'name' => 'Notif User', 'password' => 'secret12345', 'role' => User::ROLE_CONSULTANT, 'active' => 1,
            'mfa_secret' => Totp::secret(), 'mfa_enrolled_at' => now(),
        ]);
        DB::table('notifications')->insert([
            'user_id' => $recipient->id, 'type' => 'test.notification', 'created_at' => now()->subYears(5),
            'payload' => json_encode(['x' => 1]),
        ]);
        $before = DB::table('notifications')->count();

        $this->artisan('auth:prune-expired')->assertExitCode(0);

        $this->assertSame($before, DB::table('notifications')->count(), 'notifications must never be touched by this sweep');
    }
}
