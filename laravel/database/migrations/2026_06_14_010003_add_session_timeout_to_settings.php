<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 — Item 2: admin-tunable session-timeout thresholds. Additive (defaults carried), so
 * existing rows are unaffected.
 *   idle_timeout_minutes  30  — force-logout after this many minutes of inactivity
 *   abs_timeout_minutes    0  — absolute max session age in minutes; 0 = disabled
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('idle_timeout_minutes')->default(30)->after('mfa_enforcement');
            $table->unsignedSmallInteger('abs_timeout_minutes')->default(0)->after('idle_timeout_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn(['idle_timeout_minutes', 'abs_timeout_minutes']);
        });
    }
};
