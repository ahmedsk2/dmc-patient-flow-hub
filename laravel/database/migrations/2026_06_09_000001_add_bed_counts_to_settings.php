<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds licensed bed counts so the dashboard "Bed Occupancy" gauge reflects real physical capacity
 * (active ward patients ÷ ward_beds) instead of the old staffing proxy (hospitalists × max_hospitalist).
 *
 * Defaults are PLACEHOLDERS — an administrator MUST set the unit's real licensed counts in
 * Control → Settings. Additive/optional, so existing rows and saved data are unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->unsignedInteger('ward_beds')->default(50)->after('long_los');   // placeholder — set in Control
            $table->unsignedInteger('icu_beds')->default(10)->after('ward_beds');   // placeholder — set in Control
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn(['ward_beds', 'icu_beds']);
        });
    }
};
