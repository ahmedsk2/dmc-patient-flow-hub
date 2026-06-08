<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admission = one episode (the legacy `picupatients` row). A re-admission / ward<->ICU transfer
 * is a new admission row, linked to the same patient. Diagnoses are normalised into a join table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->string('bed', 64)->nullable();
            $table->string('admitted_from', 64)->nullable();   // ER / Clinic / ICU ...
            $table->date('admit_date')->nullable()->index();
            $table->string('current_location', 32)->nullable()->index(); // Ward / ICU / ER

            $table->foreignId('consultant_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('admitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('discharged_by')->nullable()->constrained('users')->nullOnDelete();

            $table->date('medical_discharge_date')->nullable();  // "medically discharged, still in"
            $table->date('discharge_date')->nullable()->index(); // file closed
            $table->string('discharge_to', 128)->nullable();
            $table->string('outcome', 32)->nullable();           // Alive / Dead / LAMA ... (was MORTALITY)
            $table->string('delay_reason', 191)->nullable();
            // discharge_from_ward | discharge_from_ICU | other_transfer | transfer_to_other_speciality | Transfer_from_ICU
            $table->string('transfer_type', 48)->nullable();
            $table->boolean('is_longterm')->default(false);
            $table->boolean('is_new_assignment')->default(false);
            $table->date('assigned_on')->nullable();
            $table->unsignedBigInteger('legacy_id')->nullable()->index(); // original picupatients.ID
            $table->timestamps();
        });

        Schema::create('admission_diagnoses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admission_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('seq')->default(1);     // 1-based order within the original array
            $table->string('icd10_code', 100)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admission_diagnoses');
        Schema::dropIfExists('admissions');
    }
};
