<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('min_hospitalist')->default(6);
            $table->unsignedInteger('max_hospitalist')->default(30);
            $table->unsignedInteger('min_subs')->default(7);
            $table->unsignedInteger('max_subs')->default(7);
            $table->unsignedInteger('short_los')->default(5);
            $table->unsignedInteger('long_los')->default(11);
            $table->unsignedTinyInteger('mfa_enforcement')->default(0); // 0 off, 1 admins, 2 all
            $table->timestamps();
        });

        Schema::create('audit_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name')->nullable();
            $table->string('action', 64)->index();
            $table->string('entity_type', 64)->nullable();
            $table->string('entity_id', 64)->nullable();
            $table->json('details')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_log');
        Schema::dropIfExists('settings');
    }
};
