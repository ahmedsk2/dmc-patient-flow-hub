<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Makes the readmission window admin-tunable (clinical sign-off 2026-06-09: default stays 3 days
 * = the "72-hour readmission" rule; the unit can adjust it in Control → Settings, with the change
 * recorded in setting_changes like every other setting).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('readmission_window_days')->default(3)->after('icu_beds');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn('readmission_window_days');
        });
    }
};
