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
 *   clock            — R2: how far the database HOST's clock has drifted from PHP's, in seconds
 *                      (positive = DB ahead of PHP). Deliberately UTC_TIMESTAMP(), never NOW() or
 *                      CURDATE() (the `mysql` connection pins no session timezone — see the "never
 *                      compare an app-written datetime against MySQL's clock" rule in CLAUDE.md —
 *                      so NOW() is the DB host's LOCAL clock, not UTC, and comparing it against
 *                      PHP's Riyadh-local `now()` would conflate a real host-clock skew with the
 *                      UTC+3 offset). Every date column here is still written/read as Riyadh-local
 *                      by the app (unaffected); this check exists only to catch the two host
 *                      clocks drifting apart — e.g. after the pending host reboot mentioned in
 *                      session memory, or a stalled/misconfigured NTP daemon — which would corrupt
 *                      every "today"/"now" rule (audit timestamps, session/MFA expiry, LOS, the
 *                      24h "New" badge) without anything else here noticing. >120s is degraded.
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

    private const CLOCK_SKEW_DEGRADED_AFTER_SECONDS = 120;

    public function show(): JsonResponse
    {
        $lastRunAt = $this->schedulerLastRunAt();
        $stale = $lastRunAt === null
            || $lastRunAt->lt(now()->subMinutes(self::SCHEDULER_STALE_AFTER_MINUTES));

        $db = $this->probeDatabase();
        $clockSkewSeconds = $db['skew_seconds'];
        // Unmeasurable (DB unreachable, or the probe query failed) reports "not known to be
        // degraded" rather than degraded — the db check below already forces $ok false in that
        // case, so this stays an honest "unknown", not a false claim of excess skew.
        $clockDegraded = $clockSkewSeconds !== null
            && abs($clockSkewSeconds) > self::CLOCK_SKEW_DEGRADED_AFTER_SECONDS;

        $checks = [
            'db' => $db['ok'],
            'storage_writable' => is_writable(storage_path('framework')),
            'scheduler' => [
                'last_run_at' => $lastRunAt?->toIso8601String(),
                'stale' => $stale,
            ],
            'clock' => [
                'skew_seconds' => $clockSkewSeconds,
                'degraded' => $clockDegraded,
            ],
        ];

        $ok = $checks['db'] && $checks['storage_writable'] && ! $stale && ! $clockDegraded;

        // This route sits outside the `web` group (routes/public.php — deliberately session-less,
        // see that file), so none of SecurityHeaders' CSP/frame/HSTS machinery applies here — there
        // is no HTML to protect and no session cookie to guard. It still needs its OWN two sensible
        // headers: a monitoring probe must never be served stale/cached by an intermediary, and
        // nosniff costs nothing on a JSON body that will never be anything else.
        return response()->json([
            'status' => $ok ? 'ok' : 'degraded',
            'checks' => $checks,
            'app' => [
                'version' => ((string) config('app.version')) ?: 'unknown',
                'timezone' => (string) config('app.timezone'),
            ],
        ], $ok ? 200 : 503)->withHeaders([
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
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
     * is honoured only at connect time), run a statement-bounded SELECT 1 + UTC_TIMESTAMP() in the
     * SAME round trip (R2 — one extra trivial column, not a second connection on a route polled up
     * to 60×/minute), and purge the clone — whatever happens. Any failure, including "default
     * connection not configured", is `['ok' => false, 'skew_seconds' => null]`.
     *
     * @return array{ok: bool, skew_seconds: ?float}
     */
    private function probeDatabase(): array
    {
        try {
            $base = config('database.connections.'.config('database.default'));
            if (! is_array($base)) {
                return ['ok' => false, 'skew_seconds' => null];
            }

            // Assigned, not array-union: the connection config now always sets its own ATTR_TIMEOUT
            // (DB_CONNECT_TIMEOUT, default 5 s) and `+` would keep that one, not the probe's 2 s.
            $base['options'] = $base['options'] ?? [];
            $base['options'][PDO::ATTR_TIMEOUT] = self::DB_TIMEOUT_SECONDS;
            config(['database.connections.'.self::PROBE_CONNECTION => $base]);

            // UTC_TIMESTAMP(), never NOW()/CURDATE() — see the class doc's `clock` entry.
            $row = DB::connection(self::PROBE_CONNECTION)
                ->selectOne('SELECT /*+ MAX_EXECUTION_TIME(2000) */ 1 AS ok, UTC_TIMESTAMP() AS db_utc_now');

            $ok = (int) ($row->ok ?? 0) === 1;
            $skewSeconds = null;
            if ($ok && ! empty($row->db_utc_now)) {
                // Positive = the database host's clock reads LATER than PHP's — i.e. the DB is
                // ahead. Both sides are plain Unix timestamps (no timezone arithmetic to get
                // wrong): PHP's own current instant vs. the DB's UTC_TIMESTAMP() parsed as UTC.
                $dbUtcNow = Carbon::parse((string) $row->db_utc_now, 'UTC');
                $skewSeconds = (float) ($dbUtcNow->getTimestamp() - Carbon::now('UTC')->getTimestamp());
            }

            return ['ok' => $ok, 'skew_seconds' => $skewSeconds];
        } catch (Throwable) {
            return ['ok' => false, 'skew_seconds' => null];
        } finally {
            DB::purge(self::PROBE_CONNECTION);
        }
    }
}
