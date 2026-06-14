<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 — Item 1: soft-deletes on admissions, consultations and users. Additive `deleted_at`
 * timestamp (NULL = live row); the Eloquent SoftDeletes trait adds the global scope so existing
 * board/list queries are unchanged. A soft-deleted user no longer triggers a physical DELETE, so
 * the nullOnDelete FKs on admissions.consultant_id/admitted_by/discharged_by are never fired —
 * historical attribution survives.
 *
 * CROSS-CUTTING RISK: the raw DB::table() analytics in Dashboard/Statistics/Reports bypass the
 * global scope and must add explicit whereNull('...deleted_at'); see migration 010002 (the marker
 * + checklist enforced by SoftDeleteTest).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admissions', fn (Blueprint $t) => $t->softDeletes());
        Schema::table('consultations', fn (Blueprint $t) => $t->softDeletes());
        Schema::table('users', fn (Blueprint $t) => $t->softDeletes());
    }

    public function down(): void
    {
        Schema::table('admissions', fn (Blueprint $t) => $t->dropSoftDeletes());
        Schema::table('consultations', fn (Blueprint $t) => $t->dropSoftDeletes());
        Schema::table('users', fn (Blueprint $t) => $t->dropSoftDeletes());
    }
};
