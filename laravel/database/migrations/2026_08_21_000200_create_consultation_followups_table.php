<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The daily follow-up log — one row per consult per day the team ticked it off.
 *
 * Copies the proven append-only shape of `handover_revisions`
 * (2026_06_11_000001_create_handover_tables.php): id + cascading FK + text body + nullable author
 * FK (nullOnDelete, so a deleted account never erases history) + created_at useCurrent and NO
 * updated_at. A tick is a fact that happened; it is appended, never edited.
 *
 * unique([consultation_id, followup_date]) is the correctness guarantee: a consult cannot be
 * double-ticked for one day, so the team's "seen 8 of 12 today" completeness count is always exact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consultation_followups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consultation_id')->constrained()->cascadeOnDelete();
            $table->date('followup_date');
            $table->text('note')->nullable();                       // optional one-liner
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();          // append-only — no updated_at
            $table->unique(['consultation_id', 'followup_date']);   // one tick per consult per day
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consultation_followups');
    }
};
