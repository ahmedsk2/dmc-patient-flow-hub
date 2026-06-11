<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * External / allied services (the legacy `other_specialities` table) live in the same
 * `specialties` table, flagged is_external. They are transfer-OUT targets only: an external
 * transfer closes the episode without opening a new one, so they never appear in the
 * internal-specialty pickers (which filter is_external = false).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('specialties', function (Blueprint $table) {
            $table->boolean('is_external')->default(false)->after('is_subspecialty');
        });
    }

    public function down(): void
    {
        Schema::table('specialties', function (Blueprint $table) {
            $table->dropColumn('is_external');
        });
    }
};
