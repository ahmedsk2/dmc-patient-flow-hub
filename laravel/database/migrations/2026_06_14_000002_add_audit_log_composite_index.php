<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 — Item 1/2: composite index (entity_type, entity_id) on audit_log.
 *
 * The per-patient activity panel (Item 2) and any entity-scoped viewer filter (Item 1) run
 * WHERE entity_type = 'admission' AND entity_id = ?. Without this index that is a full scan of
 * what will become a 100k+ row append-only table. Additive — no data change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_log', function (Blueprint $table) {
            $table->index(['entity_type', 'entity_id']);   // -> audit_log_entity_type_entity_id_index
        });
    }

    public function down(): void
    {
        Schema::table('audit_log', function (Blueprint $table) {
            $table->dropIndex('audit_log_entity_type_entity_id_index');
        });
    }
};
