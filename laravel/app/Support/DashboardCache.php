<?php

namespace App\Support;

use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * Single home for the dashboard "heavy tier" cache key + its invalidation.
 *
 * The heavy tier (per-consultant board, 6-month consultations chart, YTD, top-dx, LOS
 * buckets) is cached in DashboardController with a short TTL; the live tier (today's
 * census/occupancy/boarding, alerts, my-unit) is never cached. We bust on every
 * Admission/Consultation write via model events (Eloquent saves) AND on the
 * PatientActionController query-builder update paths that bypass model events — so a
 * clinician's own action is reflected immediately, not after the TTL.
 */
class DashboardCache
{
    public const KEY = 'dashboard.heavy';

    /** Base TTL in seconds; a small random jitter is added so expiries do not line up. */
    public const TTL = 300;

    public static function bust(): void
    {
        Cache::forget(self::KEY);
    }

    /**
     * RES-08 — single-flight recompute. On a miss (expiry or a bust from a patient-flow write)
     * only ONE request recomputes the heavy aggregations; concurrent requests wait briefly on the
     * lock and then read the fresh value instead of every ward workstation hammering the same
     * dozen queries at once. The TTL carries jitter for the same reason. Works on the `database`,
     * `file` and `array` stores (all lock-capable; `cache_locks` exists from the base migration).
     * If the lock cannot be obtained within the wait, re-read the key first (the holder has usually
     * finished by then) and only then fall through to a plain remember — a stale or duplicated
     * recompute beats an error page, and the re-read keeps a slow recompute from turning every
     * waiter into a synchronised stampede the moment the wait expires.
     *
     * @return array<string, mixed>
     */
    public static function remember(Closure $compute): array
    {
        $hit = Cache::get(self::KEY);
        if (is_array($hit)) {
            return $hit;
        }

        $ttl = self::TTL + random_int(0, 30);
        try {
            return Cache::lock(self::KEY.':lock', 10)->block(5, function () use ($compute, $ttl) {
                return Cache::remember(self::KEY, $ttl, $compute);
            });
        } catch (LockTimeoutException) {
            $late = Cache::get(self::KEY);

            return is_array($late) ? $late : Cache::remember(self::KEY, $ttl, $compute);
        }
    }
}
