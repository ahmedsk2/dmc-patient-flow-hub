<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Consultation ledger (W1) — ADDITIVE columns on `consultations`.
 *
 * Every column is nullable (or defaulted), so all 1,283 historical rows keep their exact current
 * content and remain readable/editable the moment this lands. `to_service` and `consultation_date`
 * are deliberately RETAINED unchanged: they stay the display/continuity values, while
 * `owning_specialty_id` and `requested_at` become authoritative going forward.
 *
 *  owning_specialty_id — the scoping key (nullable => "Unassigned" bucket for unmatched legacy rows)
 *  status              — new | active | ongoing | signed_off (see Consultation::STATUS_*)
 *  signed_off_by       — who signed off (today only the audit log knows)
 *  admission_id        — ties a consult to the actual stay (re-admissions each create a new row)
 *  requested_at        — REAL request time; NULL for every historical row, never fabricated
 *  signed_off_at       — REAL sign-off time; NULL for every historical row
 *  response_*          — the structured outcome captured at sign-off (W2 fills these)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consultations', function (Blueprint $table) {
            $table->foreignId('admission_id')->nullable()->after('patient_id')
                ->constrained('admissions')->nullOnDelete();          // constrained() also indexes it
            $table->dateTime('requested_at')->nullable()->after('consultation_date')->index();
            $table->foreignId('owning_specialty_id')->nullable()->after('to_service')
                ->constrained('specialties')->nullOnDelete();
            $table->string('status', 16)->default('new')->after('owning_specialty_id')->index();
            $table->foreignId('signed_off_by')->nullable()->after('status')
                ->constrained('users')->nullOnDelete();
            $table->dateTime('signed_off_at')->nullable()->after('signoff_date');
            $table->string('response_disposition', 32)->nullable()->after('signed_off_at');
            $table->boolean('response_followup_needed')->nullable()->after('response_disposition');
            $table->text('response_note')->nullable()->after('response_followup_needed');
        });
    }

    public function down(): void
    {
        Schema::table('consultations', function (Blueprint $table) {
            // dropConstrainedForeignId drops the FK, its index, and the column
            $table->dropConstrainedForeignId('admission_id');
            $table->dropConstrainedForeignId('owning_specialty_id');
            $table->dropConstrainedForeignId('signed_off_by');
            $table->dropIndex(['requested_at']);
            $table->dropIndex(['status']);
            $table->dropColumn([
                'requested_at', 'status', 'signed_off_at',
                'response_disposition', 'response_followup_needed', 'response_note',
            ]);
        });
    }
};
