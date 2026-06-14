<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 — Item 9: soft-delete on patients (additive `deleted_at`, NULL = live). The patient-merge
 * tool soft-deletes the now-empty SOURCE patient so the operation is recoverable (the row survives,
 * hidden by the SoftDeletes global scope). admissions.patient_id is cascadeOnDelete — a SOFT delete
 * never fires that FK, so the re-pointed admissions (now under the TARGET) are untouched.
 *
 * NOTE: numbered 010007 (not the spec's 000005 — that ordinal is taken by
 * 2026_06_14_000005_create_report_recipients_table) so it sorts AFTER the existing 010001 soft-delete
 * batch and migrates cleanly.
 *
 * CROSS-CUTTING: raw DB::table('patients') analytics joins bypass the global scope — but a
 * merged-away (trashed) source has NO live admissions or consultations pointing at it (they were
 * re-pointed to the target inside the merge txn), so historical id-joins resolve through the TARGET
 * and never surface the trashed source. The duplicates finder (raw DB::table) excludes it explicitly
 * with whereNull('deleted_at').
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients', fn (Blueprint $t) => $t->softDeletes());
    }

    public function down(): void
    {
        Schema::table('patients', fn (Blueprint $t) => $t->dropSoftDeletes());
    }
};
