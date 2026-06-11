<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One-time data fix: legacy consultations stored the INTERNAL specialty as its numeric id in
 * consultation_to_service (the legacy "to service" dropdown emitted speciality.id for internal
 * specialties but the NAME for other/allied services). The Wave-4a import preserved internal
 * specialty ids verbatim, so already-imported numeric values resolve safely by id. External
 * (other_specialities) ids were NOT stable (name-deduped insert) — they are intentionally
 * excluded; legacy never stored them as numbers anyway. Self-healing: rows with no id match
 * (or non-numeric text) are left untouched. Future imports resolve at import time (LegacyImport).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            UPDATE consultations c
            JOIN specialties s ON s.id = CAST(c.to_service AS UNSIGNED) AND s.is_external = 0
            SET c.to_service = s.name
            WHERE c.to_service REGEXP '^[0-9]+$'
        ");
    }

    public function down(): void
    {
        // data fix — not reversible (the numeric originals are gone by design)
    }
};
