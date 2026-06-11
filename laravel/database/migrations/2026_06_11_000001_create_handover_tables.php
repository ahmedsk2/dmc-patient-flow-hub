<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Handover feature:
 *   handovers           — the CURRENT handover text, one row per admission (upserted on save).
 *   handover_revisions  — append-only history; every save adds one row.
 *   handover_signatures — created when a patient moves between consultants with a same-day
 *                         handover; the receiving consultant signs (pinning the revision they
 *                         actually read). Unsigned rows are voided on discharge/delete/supersede.
 *   notifications       — lightweight in-app inbox feed (bell), currently handover.* only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('handovers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admission_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();   // updated_at drives the same-day transfer gate
        });

        Schema::create('handover_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admission_id')->constrained()->cascadeOnDelete();   // FK index covers the lookup
            $table->text('body');
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();   // append-only — no updated_at
        });

        Schema::create('handover_signatures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_consultant_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('to_consultant_id')->nullable()->constrained('users')->nullOnDelete();
            // the revision being signed — set to the latest at transfer time, re-bound at signing
            $table->foreignId('revision_id')->nullable()->constrained('handover_revisions')->nullOnDelete();
            $table->timestamp('required_at')->useCurrent();
            $table->timestamp('signed_at')->nullable();
            $table->foreignId('signed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();      // superseded / discharged before signing
            $table->index(['to_consultant_id', 'signed_at']);   // the "awaiting my signature" inbox
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 64);
            $table->json('payload')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id', 'read_at']);   // the shared unread COUNT on every page
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('handover_signatures');
        Schema::dropIfExists('handover_revisions');
        Schema::dropIfExists('handovers');
    }
};
