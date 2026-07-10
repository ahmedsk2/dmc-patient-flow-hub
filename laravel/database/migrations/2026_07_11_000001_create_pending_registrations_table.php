<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-07-11 auth-hardening: no self-registered account may exist until BOTH the email address
 * and a TOTP authenticator are verified. This table holds the in-progress signup, one row per
 * attempt, keyed by a random token held in the browser session (session('reg.token')) — never in
 * a URL. A half-finished signup leaves only this expirable row, never a live login.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_registrations', function (Blueprint $table) {
            $table->id();
            $table->string('token', 40)->unique();
            $table->string('email');
            // email OTP lifecycle
            $table->string('email_code_hash')->nullable();
            $table->dateTime('email_code_expires_at')->nullable();
            $table->dateTime('email_sent_at')->nullable();
            $table->unsignedInteger('email_send_count')->default(0);
            $table->unsignedInteger('email_attempts')->default(0);
            $table->dateTime('email_verified_at')->nullable();
            // TOTP provisioning (secret encrypted at rest; recovery codes held PLAINTEXT here only
            // until account creation, at which point they're hashed into the user row)
            $table->text('totp_secret')->nullable();
            $table->text('totp_recovery_codes')->nullable();
            $table->dateTime('totp_confirmed_at')->nullable();
            // whole-row TTL (30 min) — a stale row is treated as gone by the controller and is
            // opportunistically prunable by a scheduled sweep
            $table->dateTime('expires_at')->index();
            $table->timestamps();

            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_registrations');
    }
};
