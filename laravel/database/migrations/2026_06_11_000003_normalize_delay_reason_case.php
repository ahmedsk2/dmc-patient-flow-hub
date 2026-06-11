<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Normalize legacy delay_reason casing to the controlled vocabulary: the old app stored a
 * lowercase 'physical' / 'system' from one of its forms while the new validation accepts only
 * 'Physical' / 'System' (PatientActionController::medicalDischarge). One-off data fix —
 * idempotent, and a no-op on rows already cased correctly.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('admissions')->whereRaw("LOWER(delay_reason) = 'physical'")->update(['delay_reason' => 'Physical']);
        DB::table('admissions')->whereRaw("LOWER(delay_reason) = 'system'")->update(['delay_reason' => 'System']);
    }

    public function down(): void
    {
        // irreversible data normalization — the original mixed casing carries no meaning
    }
};
