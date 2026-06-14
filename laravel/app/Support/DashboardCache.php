<?php

namespace App\Support;

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

    public static function bust(): void
    {
        Cache::forget(self::KEY);
    }
}
