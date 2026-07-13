<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['log_record_opens' => 'boolean', 'mail_password' => 'encrypted'];

    /**
     * The single settings row (created with defaults on first access).
     *
     * Memoized per-request via once() — the row is read in several controllers and on every
     * authenticated request (EnsureMfaEnrolled), so this collapses many identical single-row
     * SELECTs into one. once() is request/test-scoped, so an update within the same request
     * mutates the same cached instance (no staleness).
     */
    public static function current(): self
    {
        return once(function () {
            // singleton by "first row", NOT by id: `id` is guarded, so firstOrCreate(['id'=>1])
            // silently strips the id on insert and would mint a NEW row on every call when no
            // id=1 row exists (each reader then seeing different settings)
            $s = static::query()->orderBy('id')->first() ?? static::query()->create([]);

            // a freshly-inserted row only has `id` in memory — refresh so the column DEFAULTs
            // (thresholds, bed counts, mfa policy) are actually populated on first-ever access
            return $s->wasRecentlyCreated ? $s->refresh() : $s;
        });
    }
}
