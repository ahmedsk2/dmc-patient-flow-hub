<?php

namespace App\Console\Commands;

use App\Models\Notification;
use App\Models\User;
use App\Support\S3SigV4;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * DATA-02 — daily proof that the off-box, encrypted database backup actually happened.
 *
 * The backup itself is NOT made by this app: scripts/backup/db-backup.py runs as root from host
 * cron (mysqldump inside the MySQL container → gzip → AES-256-CBC → SigV4 PUT to the backup
 * bucket) and, after every successful upload, overwrites a tiny heartbeat object
 * `{prefix}/LATEST.json` = {"object", "bytes", "sha256_of_ciphertext", "created_at"}. This command
 * is the other half of the contract: it reads that heartbeat through the same S3SigV4 signer the
 * audit archive uses (backup bucket, same endpoint/credentials), HEAD-checks the object the
 * heartbeat points at, and — if the newest backup is older than --max-age-hours, missing, or
 * cannot be verified at all — raises ONE in-app `backup.stale` notification per ACTIVE admin
 * (Observers and inactive accounts never receive one), mirroring AuditVerifyDaily's fan-out and
 * open-incident de-duplication. A storage error, an unreadable heartbeat, or an unconfigured
 * bucket is treated as a FAILURE that alerts — never a silent pass — because a backup you cannot
 * verify is, for recovery purposes, a backup you do not have.
 *
 * Unlike audit.integrity_failure (which stays open until a human looks), an open backup.stale
 * incident is auto-resolved the moment a fresh backup is observed again, so the NEXT lapse mints a
 * new alert instead of being swallowed by last month's still-open one.
 *
 * Exit code: 0 fresh, 1 anything else (cron/scheduler-visible). Secrets never reach the log,
 * the notification payload, or the console — only the bucket/key names and HTTP statuses do.
 *
 *   php artisan backup:verify [--max-age-hours=26]
 */
class BackupVerify extends Command
{
    public const TYPE = 'backup.stale';

    protected $signature = 'backup:verify
        {--max-age-hours=26 : Alert when the newest backup heartbeat is older than this many hours}';

    protected $description = 'Check the off-box DB backup heartbeat (LATEST.json) and alert active admins if the newest backup is stale, missing, or unverifiable';

    public function handle(): int
    {
        $maxAgeHours = max(1, (int) $this->option('max-age-hours'));
        $bucket = (string) config('services.db_backup.bucket');
        $heartbeatKey = trim((string) config('services.db_backup.prefix'), '/').'/LATEST.json';

        $context = [
            'bucket' => $bucket,
            'heartbeat' => $heartbeatKey,
            'max_age_hours' => $maxAgeHours,
        ];

        $client = new S3SigV4(array_merge((array) config('services.audit_archive', []), ['bucket' => $bucket]));

        if (! $client->isConfigured()) {
            return $this->raiseIncident('unconfigured', 'backup storage (AUDIT_S3_* endpoint/credentials + DB_BACKUP_S3_BUCKET) is not configured — backups cannot be verified', $context);
        }

        try {
            $raw = $client->get($heartbeatKey);
        } catch (Throwable $e) {
            return $this->raiseIncident('error', 'could not read the backup heartbeat: '.$this->safeMessage($e), $context);
        }

        if ($raw === null) {
            return $this->raiseIncident('missing', 'no backup heartbeat found — no backup has ever completed, or the bucket/prefix is wrong', $context);
        }

        $heartbeat = json_decode($raw, true);
        if (! is_array($heartbeat) || empty($heartbeat['object']) || empty($heartbeat['created_at'])) {
            return $this->raiseIncident('error', 'backup heartbeat is malformed (expected JSON with "object" and "created_at")', $context);
        }

        try {
            $createdAt = Carbon::parse((string) $heartbeat['created_at']);
        } catch (Throwable) {
            return $this->raiseIncident('error', 'backup heartbeat has an unparseable created_at', $context + ['object' => (string) $heartbeat['object']]);
        }

        $ageHours = round(max(0.0, (float) $createdAt->diffInHours(now())), 1);
        $context += [
            'object' => (string) $heartbeat['object'],
            'backup_created_at' => $createdAt->utc()->toIso8601String(),
            'age_hours' => $ageHours,
            'bytes' => $heartbeat['bytes'] ?? null,
        ];

        if ($ageHours > $maxAgeHours) {
            return $this->raiseIncident('stale', "newest backup is {$ageHours}h old (limit {$maxAgeHours}h)", $context);
        }

        // A heartbeat that points at nothing (object never landed, or was deleted by lifecycle /
        // by hand) is not a backup. One HEAD — the same call audit:ship's archive already relies on.
        try {
            $head = $client->headObject((string) $heartbeat['object']);
        } catch (Throwable $e) {
            return $this->raiseIncident('error', 'could not HEAD the backup object: '.$this->safeMessage($e), $context);
        }
        if ($head === 404) {
            return $this->raiseIncident('missing', 'the heartbeat points at an object that is not in the bucket', $context);
        }
        if ($head < 200 || $head >= 300) {
            return $this->raiseIncident('error', "HEAD of the backup object returned HTTP {$head}", $context);
        }

        $resolved = Notification::where('type', self::TYPE)->whereNull('resolved_at')->update(['resolved_at' => now()]);

        Log::info('backup.verify_ok', $context);
        $this->info("Backup OK — {$context['object']} is {$ageHours}h old (limit {$maxAgeHours}h)."
            .($resolved ? " Resolved {$resolved} open backup.stale notification(s)." : ''));

        return self::SUCCESS;
    }

    /**
     * Every non-fresh outcome funnels through here: log (error-level for infrastructure faults,
     * warning for a plain stale/missing backup), then one notification per active admin unless
     * that admin already has an unresolved backup.stale open.
     *
     * @param  array<string,mixed>  $context  bucket/key/ages only — never credentials
     */
    private function raiseIncident(string $reason, string $detail, array $context): int
    {
        $payload = ['reason' => $reason, 'detail' => $detail] + $context;

        if (in_array($reason, ['error', 'unconfigured'], true)) {
            Log::error('backup.verify_failed', $payload);
        } else {
            Log::warning('backup.stale', $payload);
        }

        $this->error("Backup check FAILED ({$reason}): {$detail}");

        $adminIds = User::where('role', User::ROLE_ADMIN)->where('active', 1)->pluck('id');
        $created = 0;

        foreach ($adminIds as $adminId) {
            $alreadyOpen = Notification::where('user_id', $adminId)
                ->where('type', self::TYPE)
                ->whereNull('resolved_at')
                ->exists();

            if ($alreadyOpen) {
                continue;
            }

            Notification::create([
                'user_id' => $adminId,
                'type' => self::TYPE,
                'created_at' => now(),
                'payload' => $payload,
            ]);
            $created++;
        }

        $this->error("Notified {$created} of {$adminIds->count()} active admin(s) (the rest already have an open backup.stale incident).");

        return self::FAILURE;
    }

    /**
     * Exception text safe for a log line / notification: class + message, capped. The HTTP client's
     * messages carry the URL (endpoint + bucket + key — fine) but never the Authorization header
     * or the secret; the cap guards against a verbose transport dump anyway.
     */
    private function safeMessage(Throwable $e): string
    {
        return mb_substr(class_basename($e).': '.$e->getMessage(), 0, 300);
    }
}
