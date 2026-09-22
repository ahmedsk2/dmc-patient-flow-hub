<?php

namespace App\Console\Commands;

use App\Models\PendingRegistration;
use App\Models\TrustedDevice;
use App\Support\Audit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * R11 — daily housekeeping sweep for three small, non-clinical auth tables. Every row this
 * command deletes is DEFINITIVELY expired by a value Laravel itself wrote (never MySQL's clock —
 * scripts/clock-guard.php enforces this; the cutoffs below are bound from PHP's `now()`):
 *
 *   - `pending_registrations` past `expires_at` (thirty-minute TTL) — these hold a PLAINTEXT TOTP
 *     recovery-code set until the account row is created, so this sweep is a security control,
 *     not just housekeeping (see the table's own migration doc-comment).
 *   - `trusted_devices` past `expires_at` — the opt-in MFA-skip cookie (2026-07-19 design). This
 *     is a SEPARATE feature from the login "remember me" cookie, which is permanently disabled
 *     (see AuthController); trusted-device is unaffected by that and is still live (gated by
 *     `settings.mfa_trusted_device_hours`, verified against AuthController::login and MfaController
 *     2026-09-22), so its expired rows are in scope here. Only `expires_at` is checked — a REVOKED
 *     but not-yet-expired row is not "expired" and is left alone; its retention is a separate,
 *     un-built policy decision (docs/compliance/DATA-RETENTION.md §2.4).
 *   - `password_reset_tokens` older than `config('auth.passwords.users.expire')` minutes (default
 *     60) — the same cutoff Laravel's own `auth:clear-resets` uses, computed here (rather than by
 *     shelling out to that command) so the row count is available for the digest below.
 *
 * Never touches `audit_log` or `notifications` — both are out of scope for this sweep by design.
 * Scheduled daily in routes/console.php. Deletes by default (unattended-safe: every row it can
 * touch is a definitionally-expired security artifact, not clinical data); `--dry-run` previews
 * without deleting. Writes ONE audit_log row with the per-table counts, and only when something
 * was actually deleted — a no-op run leaves no trail, exactly like dq:notify's "nothing to report"
 * case.
 *
 *   php artisan auth:prune-expired             # deletes expired rows, prints counts
 *   php artisan auth:prune-expired --dry-run    # reports counts only, deletes nothing
 */
class AuthPruneExpired extends Command
{
    protected $signature = 'auth:prune-expired {--dry-run : report counts only, delete nothing}';

    protected $description = 'Delete pending_registrations, trusted_devices and password_reset_tokens rows that are past their expiry';

    public function handle(): int
    {
        $now = now();
        $resetCutoff = $now->copy()->subMinutes((int) config('auth.passwords.users.expire', 60));

        $pending = fn () => PendingRegistration::query()->where('expires_at', '<', $now);
        $trusted = fn () => TrustedDevice::query()->where('expires_at', '<', $now);
        $resets = fn () => DB::table('password_reset_tokens')->where('created_at', '<', $resetCutoff);

        $counts = [
            'pending_registrations' => $pending()->count(),
            'trusted_devices' => $trusted()->count(),
            'password_reset_tokens' => $resets()->count(),
        ];
        $total = array_sum($counts);

        if ($this->option('dry-run')) {
            $this->info("DRY RUN: {$total} row(s) eligible — ".json_encode($counts).'. Re-run without --dry-run to delete.');

            return self::SUCCESS;
        }

        if ($total === 0) {
            $this->info('nothing expired — nothing deleted.');

            return self::SUCCESS;
        }

        // One transaction around all three deletes AND the audit write: without it, an interruption
        // between statements (lock-wait timeout from a concurrent login/reset request, dropped
        // connection) could leave earlier deletes committed while the run never reaches Audit::log,
        // silently breaking the "every deletion has exactly one audit row" guarantee. Audit::log()
        // opens its own transaction internally, which nests safely as a savepoint here.
        $deleted = DB::transaction(function () use ($pending, $trusted, $resets) {
            $deleted = [
                'pending_registrations' => $pending()->delete(),
                'trusted_devices' => $trusted()->delete(),
                'password_reset_tokens' => $resets()->delete(),
            ];

            Audit::log('auth.expired_rows_pruned', 'auth', null, $deleted);

            return $deleted;
        });

        $this->info('deleted '.array_sum($deleted).' row(s) — '.json_encode($deleted));

        return self::SUCCESS;
    }
}
