<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hard-guarantee one diagnosis row per (admission, ICD-10 code) — prevents duplicate diagnosis
 * links regardless of code path. Self-healing: removes any pre-existing duplicates (keeping the
 * lowest id) before adding the unique index, so it applies cleanly on legacy-imported data.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DELETE ad1 FROM admission_diagnoses ad1
            JOIN admission_diagnoses ad2
              ON ad1.admission_id = ad2.admission_id
             AND ad1.icd10_code   = ad2.icd10_code
             AND ad1.id           > ad2.id');

        Schema::table('admission_diagnoses', function (Blueprint $table) {
            $table->unique(['admission_id', 'icd10_code'], 'adx_admission_code_unique');
        });
    }

    public function down(): void
    {
        Schema::table('admission_diagnoses', function (Blueprint $table) {
            $table->dropUnique('adx_admission_code_unique');
        });
    }
};
