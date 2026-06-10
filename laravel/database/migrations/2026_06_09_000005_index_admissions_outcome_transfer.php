<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Performance indexes (no formula/behavior change — access path only):
 *  - (outcome, discharge_date): death metrics filter `outcome='Dead'` + a discharge-date range;
 *    without it the optimizer abandons the date index and full-scans ~35k rows.
 *  - (transfer_type, discharge_date, patient_id): the 72h-readmission self-join leads on
 *    `transfer_type IN (...)`; unindexed it full-scans the `prev` side (~1.1s all-time).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admissions', function (Blueprint $table) {
            $table->index(['outcome', 'discharge_date'], 'idx_adm_outcome_disch');
            $table->index(['transfer_type', 'discharge_date', 'patient_id'], 'idx_adm_transfer_disch_patient');
        });
    }

    public function down(): void
    {
        Schema::table('admissions', function (Blueprint $table) {
            $table->dropIndex('idx_adm_outcome_disch');
            $table->dropIndex('idx_adm_transfer_disch_patient');
        });
    }
};
