<?php

namespace App\Support;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;
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
     * @param string      $action     e.g. 'admission.discharge', 'user.delete'
     * @param string      $entityType e.g. 'admission', 'consultation', 'user'
     * @param string|null $entityId   string-cast PK, or null for bulk/system actions
     * @param array       $details    arbitrary JSON context (before/after values, etc.)
     */
    public static function log(
        string $action,
        string $entityType,
        ?string $entityId = null,
        array $details = []
    ): void {
        AuditLog::create([
            'actor_id' => Auth::id(),
            'actor_name' => Auth::user()?->name,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'details' => $details,
            'ip' => Request::ip(),
        ]);
    }
}
