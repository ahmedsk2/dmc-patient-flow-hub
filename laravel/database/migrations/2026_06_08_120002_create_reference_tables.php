<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('specialties', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('is_subspecialty')->default(true); // specialty 1 = Hospitalist in legacy
            $table->timestamps();
        });

        Schema::create('icd10', function (Blueprint $table) {
            $table->id();
            $table->string('code', 100)->index();   // ICD-10 code (legacy icd10.id)
            $table->string('name', 255)->nullable();
            $table->index(['code', 'name'], 'idx_icd10_code_name'); // covering — fast dx lookups
        });

        Schema::create('consultation_reasons', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });

        Schema::create('tb_diagnoses', function (Blueprint $table) {
            $table->id();
            $table->string('icd10_code', 100)->index(); // codes that classify a patient as TB
        });

        Schema::create('countries', function (Blueprint $table) {
            $table->id();
            $table->string('code', 8)->nullable();
            $table->string('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('countries');
        Schema::dropIfExists('tb_diagnoses');
        Schema::dropIfExists('consultation_reasons');
        Schema::dropIfExists('icd10');
        Schema::dropIfExists('specialties');
    }
};
