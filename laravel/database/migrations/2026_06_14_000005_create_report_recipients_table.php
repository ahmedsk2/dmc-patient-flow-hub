<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 — §3.3: recipients of the scheduled monthly report email. A dedicated table (not a
 * comma-separated settings column) so each address can be individually enabled/disabled without
 * corrupting the singleton settings row. Append-only style (no updated_at), matching audit_log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_recipients', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('email', 255)->unique();
            $table->boolean('active')->default(true);
            $table->foreignId('added_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index('active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_recipients');
    }
};
