<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Consultation ledger W0 — cutover safety gate.
 *
 * `php artisan legacy:import` rebuilds the new schema from the original DMC database, and every
 * column of the consultation ledger is absent from that legacy schema. Once the doctors start
 * entering consultations HERE, a reload must not replay the legacy table over them.
 *
 *   consultations_source_of_truth = false (default)  legacy:import behaves exactly as before
 *   consultations_source_of_truth = true             legacy:import leaves `consultations` alone
 *
 * Additive and reversible; existing rows keep the default, so nothing changes until it is flipped
 * at cutover.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->boolean('consultations_source_of_truth')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn('consultations_source_of_truth');
        });
    }
};
