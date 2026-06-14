<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Phase 2 — Item 4: field-level before/after diffs for audit details.
 *
 * Turns a pair of attribute snapshots into a structured {field: {from, to}} payload so the audit
 * viewer + per-patient activity panel can show exactly WHAT changed on a modify/update (was the
 * admit date corrected? was the outcome flipped?). Diagnosis sets get a dedicated added/removed
 * shape. Pure static helper — no state, no DB.
 */
class AuditDiff
{
    /**
     * Compute {field: {from: oldVal, to: newVal}} for every field that changed.
     *
     * @param array $before assoc array of old values keyed by field name
     * @param array $after  assoc array of new values (same keys; extra keys in $after appear as new)
     * @param array $omit   field names to skip entirely (passwords, internal flags)
     */
    public static function diff(array $before, array $after, array $omit = []): array
    {
        $changes = [];
        $keys = array_unique(array_merge(array_keys($before), array_keys($after)));
        foreach ($keys as $key) {
            if (in_array($key, $omit, true)) {
                continue;
            }
            $old = $before[$key] ?? null;
            $new = $after[$key] ?? null;
            // string-cast both sides for comparison so int 1 vs "1" is NOT a false change
            if (self::scalar($old) !== self::scalar($new)) {
                $changes[$key] = ['from' => $old, 'to' => $new];
            }
        }

        return $changes;
    }

    /** Normalise a value to a comparable string (arrays compared by canonical JSON). */
    private static function scalar(mixed $v): string
    {
        if (is_array($v)) {
            return json_encode($v);
        }
        if (is_bool($v)) {
            return $v ? '1' : '0';
        }

        return (string) ($v ?? '');
    }

    /**
     * Convenience: diff an Eloquent model's dirty fields using getOriginal() / getAttributes().
     * Call BEFORE ->save() / ->update() (while the model is still dirty) to capture originals.
     * Returns the same {field:{from,to}} shape.
     */
    public static function fromModel(Model $model, array $omit = []): array
    {
        $dirty = array_keys($model->getDirty());
        $before = array_intersect_key($model->getOriginal(), array_flip($dirty));
        $after = array_intersect_key($model->getAttributes(), array_flip($dirty));

        return self::diff($before, $after, $omit);
    }

    /**
     * Diagnosis code-set diff: {added:[...codes], removed:[...codes]}, or null if unchanged.
     * Inputs are flat arrays of ICD-10 code strings (order-insensitive).
     */
    public static function diagnosisDiff(array $oldCodes, array $newCodes): ?array
    {
        $added = array_values(array_diff($newCodes, $oldCodes));
        $removed = array_values(array_diff($oldCodes, $newCodes));
        if (! $added && ! $removed) {
            return null;
        }

        return ['added' => $added, 'removed' => $removed];
    }
}
