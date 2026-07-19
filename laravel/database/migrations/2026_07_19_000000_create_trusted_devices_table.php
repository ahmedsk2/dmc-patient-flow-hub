<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trusted device — opt-in MFA (TOTP-only) skip for one browser, for a bounded window.
 *
 * One row per trusted browser per user. The cookie carries "selector:validator": the selector is
 * the indexed lookup key, the validator is only ever stored as its SHA-256 — so a database read
 * yields nothing replayable. SHA-256 (not bcrypt) is correct here: the validator is 32 bytes of
 * CSPRNG output, there is nothing to brute-force, and bcrypt on every login is a needless cost.
 *
 * `expires_at` is a FIXED window from the moment trust was granted and is NEVER extended by use.
 * `revoked_at` is set instead of deleting the row, so the trail survives revocation.
 *
 * Also adds the admin-tunable window: `settings.mfa_trusted_device_hours` (default 24; 0 disables
 * the feature outright — no cookie is issued and any existing one is ignored).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trusted_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('selector', 32)->unique();
            $table->string('validator_hash', 64);
            $table->string('label')->nullable();
            $table->string('ip', 45)->nullable();
            $table->dateTime('expires_at')->index();
            $table->dateTime('revoked_at')->nullable();
            $table->dateTime('last_used_at')->nullable();
            $table->timestamps();
        });

        Schema::table('settings', function (Blueprint $table) {
            $table->unsignedInteger('mfa_trusted_device_hours')->default(24);
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn('mfa_trusted_device_hours');
        });

        Schema::dropIfExists('trusted_devices');
    }
};
