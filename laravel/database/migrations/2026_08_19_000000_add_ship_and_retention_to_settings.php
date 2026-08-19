<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tasks #230/#232 — off-box audit-log shipping (audit:ship) high-water mark, and the retention
 * window that gates the (never-scheduled, --confirm-gated) audit:prune command.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $t) {
            $t->unsignedBigInteger('audit_shipped_through_id')->default(0);
            $t->unsignedInteger('audit_retention_years')->default(6);
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $t) {
            $t->dropColumn(['audit_shipped_through_id', 'audit_retention_years']);
        });
    }
};
