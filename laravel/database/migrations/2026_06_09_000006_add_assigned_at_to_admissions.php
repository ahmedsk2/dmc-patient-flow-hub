<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Precise timestamp of when a consultant was assigned, so "New" can be a rolling 24-hour window
 * (clinical sign-off 2026-06-09) instead of the sticky `is_new_assignment` flag that only ever grew.
 * Nullable + null on historical rows = correctly "not new".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admissions', function (Blueprint $table) {
            $table->timestamp('assigned_at')->nullable()->after('assigned_on');
        });
    }

    public function down(): void
    {
        Schema::table('admissions', function (Blueprint $table) {
            $table->dropColumn('assigned_at');
        });
    }
};
