<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consultations', function (Blueprint $table) {
            $table->id();
            $table->string('mrn', 64)->nullable()->index();   // clean VARCHAR (legacy was INT — truncated)
            $table->foreignId('patient_id')->nullable()->constrained()->nullOnDelete();
            $table->string('patient_name')->nullable();
            $table->unsignedSmallInteger('age')->nullable();
            $table->string('bed', 64)->nullable();
            $table->string('current_location', 32)->nullable();
            $table->date('consultation_date')->nullable()->index();
            $table->string('consultation_from', 128)->nullable();
            $table->json('indication')->nullable();           // consultation_reason ids
            $table->string('other_indication', 255)->nullable();
            $table->string('to_service', 128)->nullable();
            $table->foreignId('consultant_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('signoff_date')->nullable()->index();
            $table->unsignedBigInteger('legacy_id')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consultations');
    }
};
