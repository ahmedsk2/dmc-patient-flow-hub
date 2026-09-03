<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;

/**
 * The ONE audit-row writer. Actor identity and IP always come from the session/request; the caller
 * supplies only what happened and to what. Centralising this means a future schema change (a new
 * column, a different IP resolution) is a one-file edit instead of touching every controller.
 *
 * Behavior-preserving: the rows written here are byte-identical to the inline
 * AuditLog::create([...]) sites it replaces.
 */
final class Audit
{
    /**
     * Write one audit row. Actor identity and IP always come from the session/request;
     * the caller supplies what happened and to what.
     *
     * @param  string  $action  e.g. 'admission.discharge', 'user.delete'
     * @param  string  $entityType  e.g. 'admission', 'consultation', 'user'
     * @param  string|null  $entityId  string-cast PK, or null for bulk/system actions
     * @param  array  $details  arbitrary JSON context (before/after values, etc.)
     */
    public static function log(
        string $action,
        string $entityType,
        ?string $entityId = null,
        array $details = []
    ): void {
        // Wrap in a transaction so the hash-chain append is atomic: AuditLog's `creating` hook takes
        // a `lockForUpdate` on the predecessor row_hash and the `created` hook stamps this row's
        // row_hash in a SECOND statement. Under autocommit that lock releases between the two, so two
        // concurrent audit writes could read the same predecessor (or a still-NULL row_hash) and fork
        // the chain — making `audit:verify` raise false tamper alarms. One transaction holds the lock
        // across both statements, serialising concurrent appends. (Nests safely as a savepoint when a
        // caller already opened a transaction.)
        DB::transaction(fn () => AuditLog::create([
            'actor_id' => Auth::id(),
            'actor_name' => Auth::user()?->name,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'details' => $details,
            'ip' => Request::ip(),
        ]));
    }
}
