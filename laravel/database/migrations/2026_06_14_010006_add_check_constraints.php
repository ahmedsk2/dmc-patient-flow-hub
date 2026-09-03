<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 4 — Item 7: structural CHECK constraints that make impossible data unstorable regardless of
 * the code path that writes the row:
 *   - discharge_date >= admit_date (when both set)
 *   - medical_discharge_date >= admit_date (when both set)
 *   - discharge_date >= medical_discharge_date (when both set)
 *   - patients.age in [0, 150]
 *
 * Self-healing (same pattern as 2026_06_09_000002_unique_admission_diagnoses): BEFORE adding the
 * constraints we report any existing violations to the audit log (informational), then NULL out the
 * impossible date (conservative — keep the row + the audit trail) so the ALTER applies cleanly on
 * legacy data. CHECK requires MariaDB 10.2+ / MySQL 8.0.16+.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Step 1 — report existing date-ordering violations to the audit log (informational)
        $violators = DB::select('
            SELECT id, admit_date, discharge_date, medical_discharge_date
            FROM admissions
            WHERE (discharge_date IS NOT NULL AND admit_date IS NOT NULL AND discharge_date < admit_date)
               OR (medical_discharge_date IS NOT NULL AND admit_date IS NOT NULL AND medical_discharge_date < admit_date)
               OR (discharge_date IS NOT NULL AND medical_discharge_date IS NOT NULL AND discharge_date < medical_discharge_date)
        ');
        if ($violators) {
            DB::table('audit_log')->insert([
                'actor_id' => null, 'actor_name' => 'migration',
                'action' => 'migration.check_constraint_violation_report',
                'entity_type' => 'admission', 'entity_id' => null,
                'details' => json_encode(['count' => count($violators), 'ids' => array_column($violators, 'id')]),
                'ip' => '127.0.0.1', 'created_at' => now(),
            ]);
        }

        // Step 2 — self-heal: NULL the impossible date (keep the row). Order matters: fix the
        // admit-relative inversions first, then the discharge-vs-medical inversion on what remains.
        DB::statement('UPDATE admissions SET discharge_date = NULL
            WHERE discharge_date IS NOT NULL AND admit_date IS NOT NULL AND discharge_date < admit_date');
        DB::statement('UPDATE admissions SET medical_discharge_date = NULL
            WHERE medical_discharge_date IS NOT NULL AND admit_date IS NOT NULL AND medical_discharge_date < admit_date');
        DB::statement('UPDATE admissions SET discharge_date = NULL
            WHERE discharge_date IS NOT NULL AND medical_discharge_date IS NOT NULL AND discharge_date < medical_discharge_date');

        // age out of range (unsignedSmallInt is already >= 0; cap the high end)
        DB::statement('UPDATE patients SET age = NULL WHERE age IS NOT NULL AND age > 150');

        // Step 3 — add the named CHECK constraints
        DB::statement('ALTER TABLE admissions ADD CONSTRAINT chk_discharge_gte_admit
            CHECK (discharge_date IS NULL OR admit_date IS NULL OR discharge_date >= admit_date)');
        DB::statement('ALTER TABLE admissions ADD CONSTRAINT chk_medical_discharge_gte_admit
            CHECK (medical_discharge_date IS NULL OR admit_date IS NULL OR medical_discharge_date >= admit_date)');
        DB::statement('ALTER TABLE admissions ADD CONSTRAINT chk_discharge_gte_medical
            CHECK (discharge_date IS NULL OR medical_discharge_date IS NULL OR discharge_date >= medical_discharge_date)');
        DB::statement('ALTER TABLE patients ADD CONSTRAINT chk_age_range
            CHECK (age IS NULL OR (age >= 0 AND age <= 150))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE admissions DROP CONSTRAINT chk_discharge_gte_admit');
        DB::statement('ALTER TABLE admissions DROP CONSTRAINT chk_medical_discharge_gte_admit');
        DB::statement('ALTER TABLE admissions DROP CONSTRAINT chk_discharge_gte_medical');
        DB::statement('ALTER TABLE patients DROP CONSTRAINT chk_age_range');
    }
};
