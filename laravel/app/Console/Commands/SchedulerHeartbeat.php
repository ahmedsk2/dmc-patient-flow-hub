<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * 2026-09 prod-readiness (OBS-07): scheduler liveness beacon. Scheduled every minute in
 * routes/console.php; stamps now() into the cache so GET /health (HealthController) can tell a
 * running scheduler from a silently-dead one (stale after 5 minutes). Deliberately trivial — no
 * DB, no mail, nothing that could itself fail — and stored `forever` rather than with a TTL so a
 * dead scheduler still reports WHEN it last ran instead of a bare null.
 *
 * Ops note: the cron user and the web user must share the cache store (CACHE_STORE=file means the
 * same storage/framework/cache directory, readable by both) or the web probe never sees the beacon.
 *
 *   php artisan scheduler:heartbeat
 */
class SchedulerHeartbeat extends Command
{
    public const CACHE_KEY = 'scheduler.heartbeat';

    protected $signature = 'scheduler:heartbeat';

    protected $description = 'Stamp the scheduler liveness beacon read by GET /health';

    public function handle(): int
    {
        Cache::forever(self::CACHE_KEY, now()->toIso8601String());

        return self::SUCCESS;
    }
}
