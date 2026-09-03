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
 * It ALSO checks the second half of the recovery story: the hourly binary-log shipper
 * (scripts/backup/binlog-ship.py, docs/BACKUP-AND-RESTORE.md §10) writes its own
 * `{binlog_prefix}/LATEST.json` with the same field names, and without it the RPO is 24 hours
 * instead of one. A MISSING binlog heartbeat means point-in-time recovery is simply not installed
 * yet — a documented state, reported but never alerted, because an alert nobody can action is noise.
 * A heartbeat that has gone stale, or one that reports per-file failures while looking fresh, is a
 * real incident and alerts exactly like a stale dump.
 *
 * Known limitation: this command is scheduled DAILY (06:30), so a dead hourly shipper surfaces
 * in-app within a day, not within the 2-hour window itself. The faster signal is the shipper's own
 * non-zero exit in /var/log/dmc-binlog-ship.cron.log; this is the backstop that makes sure a silent
 * stop cannot go unnoticed indefinitely.
 *
 * Exit code: 0 fresh, 1 anything else (cron/scheduler-visible). Secrets never reach the log,
 * the notification payload, or the console — only the bucket/key names and HTTP statuses do.
 *
 *   php artisan backup:verify [--max-age-hours=26] [--binlog-max-age-hours=2]
 */
class BackupVerify extends Command
{
    public const TYPE = 'backup.stale';

    protected $signature = 'backup:verify
        {--max-age-hours=26 : Alert when the newest backup heartbeat is older than this many hours}
        {--binlog-max-age-hours= : Alert when the binlog shipper heartbeat is older than this many hours (default: services.db_backup.binlog_max_age_hours)}';

    protected $description = 'Check the off-box DB backup heartbeat (LATEST.json) and the hourly binlog shipper heartbeat, and alert active admins if either is stale, missing, or unverifiable';

    /** Console/log wording for the point-in-time half, set by checkBinlogHeartbeat(). */
    private string $binlogSummary = 'not checked';

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

        // The dump is provably there. Now the increment that turns "last night" into "any second":
        // the hourly binlog shipper's heartbeat (§10). Checked second because a dump you cannot
        // verify is the bigger problem, and its incident should be the one that gets raised.
        $binlogIncident = $this->checkBinlogHeartbeat($client);
        if ($binlogIncident !== null) {
            return $this->raiseIncident($binlogIncident['reason'], $binlogIncident['detail'],
                $context + $binlogIncident['context']);
        }

        $resolved = Notification::where('type', self::TYPE)->whereNull('resolved_at')->update(['resolved_at' => now()]);

        Log::info('backup.verify_ok', $context + ['binlogs' => $this->binlogSummary]);
        $this->info("Backup OK — {$context['object']} is {$ageHours}h old (limit {$maxAgeHours}h)."
            ." Point-in-time recovery: {$this->binlogSummary}."
            .($resolved ? " Resolved {$resolved} open backup.stale notification(s)." : ''));

        return self::SUCCESS;
    }

    /**
     * The point-in-time half. Returns null when there is nothing to alert about — either the shipper
     * is fresh, or it is NOT INSTALLED, which is a documented state (BACKUP-AND-RESTORE.md §10.2)
     * rather than an incident: in that state the RPO is the nightly 24 h, which the check above
     * already covers, and nagging every admin daily about a cron line only an operator can install
     * would train them to ignore backup.stale.
     *
     * The object the heartbeat names is deliberately NOT HEAD-checked: unlike the single nightly
     * dump, binary logs are a stream of objects under a lifecycle rule, so one having aged out is
     * normal and would produce a false alarm. Completeness of the chain is the shipper's own job
     * (it detects and records expired-unshipped holes) and `--restore-check`'s.
     *
     * @return array{reason:string,detail:string,context:array<string,mixed>}|null
     */
    private function checkBinlogHeartbeat(S3SigV4 $client): ?array
    {
        $maxAgeHours = max(1, (int) ($this->option('binlog-max-age-hours')
            ?? config('services.db_backup.binlog_max_age_hours', 2)));
        $key = trim((string) config('services.db_backup.binlog_prefix'), '/').'/LATEST.json';
        $context = ['binlog_heartbeat' => $key, 'binlog_max_age_hours' => $maxAgeHours];

        try {
            $raw = $client->get($key);
        } catch (Throwable $e) {
            return [
                'reason' => 'binlog_error',
                'detail' => 'could not read the binlog heartbeat: '.$this->safeMessage($e),
                'context' => $context,
            ];
        }

        if ($raw === null) {
            $this->binlogSummary = 'NOT INSTALLED — no binlog heartbeat; the RPO is the nightly 24 h (see BACKUP-AND-RESTORE.md §10.2)';
            Log::info('backup.binlog_not_installed', $context);
            $this->line("Point-in-time recovery: {$this->binlogSummary}");

            return null;
        }

        $heartbeat = json_decode($raw, true);
        if (! is_array($heartbeat) || empty($heartbeat['created_at'])) {
            return [
                'reason' => 'binlog_error',
                'detail' => 'binlog heartbeat is malformed (expected JSON with "created_at")',
                'context' => $context,
            ];
        }

        try {
            $createdAt = Carbon::parse((string) $heartbeat['created_at']);
        } catch (Throwable) {
            return [
                'reason' => 'binlog_error',
                'detail' => 'binlog heartbeat has an unparseable created_at',
                'context' => $context,
            ];
        }

        $ageHours = round(max(0.0, (float) $createdAt->diffInHours(now())), 1);
        $failed = (array) ($heartbeat['failed_binlogs'] ?? []);
        $failedCount = (int) ($heartbeat['failed_this_run'] ?? count($failed));
        $context += [
            'binlog_created_at' => $createdAt->utc()->toIso8601String(),
            'binlog_age_hours' => $ageHours,
            'binlog_object' => $heartbeat['object'] ?? null,
            'binlog_failed_this_run' => $failedCount,
            'binlog_known_gaps' => count((array) ($heartbeat['known_gaps'] ?? [])),
        ];

        if ($ageHours > $maxAgeHours) {
            return [
                'reason' => 'binlog_stale',
                'detail' => "the binlog shipper last completed {$ageHours}h ago (limit {$maxAgeHours}h) — "
                    .'point-in-time recovery is falling behind and the RPO is drifting back towards 24 h',
                'context' => $context,
            ];
        }

        // A fresh heartbeat with failures is the dangerous case: the shipper is alive, so an
        // age-only check would call it healthy while binary logs quietly never reach the bucket.
        if ($failedCount > 0) {
            // Defensive: our shipper writes plain names, but a nested value must not turn the
            // warning into an ErrorException that aborts the command after the dump check.
            $names = implode(', ', array_map(
                fn ($v) => is_scalar($v) ? (string) $v : (json_encode($v) ?: '?'),
                array_slice($failed, 0, 5),
            ));

            return [
                'reason' => 'binlog_failed',
                'detail' => "the last binlog shipping run could not archive {$failedCount} file(s)"
                    .($names !== '' ? ": {$names}" : '').' — see /var/log/dmc-binlog-ship.log',
                'context' => $context,
            ];
        }

        $this->binlogSummary = "fresh ({$ageHours}h old, limit {$maxAgeHours}h)";

        return null;
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

        if (in_array($reason, ['error', 'unconfigured', 'binlog_error'], true)) {
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
