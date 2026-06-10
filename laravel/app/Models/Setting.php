<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $guarded = ['id'];

    /** The single settings row (created with defaults on first access). */
    public static function current(): self
    {
        // singleton by "first row", NOT by id: `id` is guarded, so firstOrCreate(['id'=>1])
        // silently strips the id on insert and would mint a NEW row on every call when no
        // id=1 row exists (each reader then seeing different settings)
        $s = static::query()->orderBy('id')->first() ?? static::query()->create([]);

        // a freshly-inserted row only has `id` in memory — refresh so the column DEFAULTs
        // (thresholds, bed counts, mfa policy) are actually populated on first-ever access
        return $s->wasRecentlyCreated ? $s->refresh() : $s;
    }
}
