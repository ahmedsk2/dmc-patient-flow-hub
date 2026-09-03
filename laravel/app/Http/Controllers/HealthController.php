<?php

namespace App\Http\Controllers;

use App\Console\Commands\SchedulerHeartbeat;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PDO;
use Throwable;

/**
 * 2026-09 prod-readiness (OBS-07): the DEEP health probe. Laravel's stock `/up` (bootstrap/app.php,
 * what Coolify polls) is static — it answers 200 with the database down and the scheduler dead.
 * This one actually checks:
 *
 *   db               — a fresh, 2-second-bounded connection + SELECT 1. A NEW connection, not the
 *                      request's lazily-opened one, so it proves the server still ACCEPTS
 *                      connections rather than reusing one that happened to be alive.
 *   storage_writable — storage/framework (sessions / cache / compiled views) is writable.
 *   scheduler        — the liveness beacon `scheduler:heartbeat` stamps every minute (see that
 *                      command + routes/console.php); stale after 5 minutes. This project has
 *                      already had a silently-dead scheduler (monthly report, audit shipping and
 *                      the integrity check all quietly stopped) — a stale beacon is how a monitor
 *                      catches that now.
 *
 * 200 `ok` when every check passes, 503 `degraded` when any fails. Unauthenticated + session-less
 * (routes/public.php) and throttled. Carries NO PHI and NO secrets: no hostnames, DB names, paths
 * or error messages — a failed check is just `false`; the reason belongs in the log, not on an
 * open endpoint.
 */
class HealthController extends Controller
{
    /** Ephemeral connection name for the DB probe — cloned from the default, purged after use. */
    private const PROBE_CONNECTION = 'health-probe';

    private const DB_TIMEOUT_SECONDS = 2;

    private const SCHEDULER_STALE_AFTER_MINUTES = 5;

    public function show(): JsonResponse
    {
        $lastRunAt = $this->schedulerLastRunAt();
        $stale = $lastRunAt === null
            || $lastRunAt->lt(now()->subMinutes(self::SCHEDULER_STALE_AFTER_MINUTES));

        $checks = [
            'db' => $this->databaseAcceptsConnections(),
            'storage_writable' => is_writable(storage_path('framework')),
            'scheduler' => [
                'last_run_at' => $lastRunAt?->toIso8601String(),
                'stale' => $stale,
            ],
        ];

        $ok = $checks['db'] && $checks['storage_writable'] && ! $stale;

        return response()->json([
            'status' => $ok ? 'ok' : 'degraded',
            'checks' => $checks,
            'app' => [
                'version' => ((string) config('app.version')) ?: 'unknown',
                'timezone' => (string) config('app.timezone'),
            ],
        ], $ok ? 200 : 503);
    }

    private function schedulerLastRunAt(): ?Carbon
    {
        $stamp = Cache::get(SchedulerHeartbeat::CACHE_KEY);
        if (! is_string($stamp) || $stamp === '') {
            return null;
        }

        try {
            return Carbon::parse($stamp);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Clone the default connection under a throwaway name with a connect timeout (PDO::ATTR_TIMEOUT
     * is honoured only at connect time), run a statement-bounded SELECT 1, and purge the clone —
     * whatever happens. Any failure, including "default connection not configured", is `false`.
     */
    private function databaseAcceptsConnections(): bool
    {
        try {
            $base = config('database.connections.' . config('database.default'));
            if (! is_array($base)) {
                return false;
            }

            $base['options'] = ($base['options'] ?? []) + [PDO::ATTR_TIMEOUT => self::DB_TIMEOUT_SECONDS];
            config(['database.connections.' . self::PROBE_CONNECTION => $base]);

            $row = DB::connection(self::PROBE_CONNECTION)
                ->selectOne('SELECT /*+ MAX_EXECUTION_TIME(2000) */ 1 AS ok');

            return (int) ($row->ok ?? 0) === 1;
        } catch (Throwable) {
            return false;
        } finally {
            DB::purge(self::PROBE_CONNECTION);
        }
    }
}
