<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 — Item 6: data-quality stale-episode LOS multiplier. The data-quality dashboard flags
 * active non-long-term episodes whose LOS exceeds long_los × this multiplier (default 2 => > 22d
 * by default). Additive; default carried.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('dq_los_multiplier')->default(2)->after('failed_login_notify_threshold');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn('dq_los_multiplier');
        });
    }
};
