<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only history of operational-settings changes (one row per changed field per save),
 * so values like ward bed capacity are trackable over time — "what was ward_beds in March?"
 * is answerable from the database, not from memory.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('setting_changes', function (Blueprint $table) {
            $table->id();
            $table->string('field', 64)->index();
            $table->string('old_value', 191)->nullable();
            $table->string('new_value', 191);
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('setting_changes');
    }
};
