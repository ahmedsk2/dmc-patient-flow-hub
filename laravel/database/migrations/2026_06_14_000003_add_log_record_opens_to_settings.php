<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 — Item 3 (PHI-read logging): an admin-tunable flag that, when ON, additionally logs a
 * break-glass audit row each time a single registry record's detail panel is opened. DEFAULT OFF
 * — the request-level search/export logs already cover the access event; per-record logging is a
 * heavier, opt-in posture for stricter break-glass regimes. Additive (carries a default).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->boolean('log_record_opens')->default(false)->after('mfa_enforcement');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn('log_record_opens');
        });
    }
};
