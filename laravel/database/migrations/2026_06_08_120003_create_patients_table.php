<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Canonical patient (one row per MRN) — the identity the legacy schema never had.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patients', function (Blueprint $table) {
            $table->id();
            $table->string('mrn', 64)->unique();      // clean, indexed identifier (was mediumtext/int)
            $table->string('name')->nullable();
            $table->string('gender', 16)->nullable();
            $table->unsignedSmallInteger('age')->nullable();
            $table->string('nationality')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('patients');
    }
};
