<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wave 2, Item 10 (first-login onboarding tour): a per-user "seen the tour" timestamp. Additive +
 * nullable (mirrors mfa_enrolled_at) — NULL means "never seen", which auto-starts the tour on the
 * next authenticated load. It is a UI preference, not PHI/clinical, so no audit on write.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dateTime('tour_completed_at')->nullable()->after('mfa_enrolled_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('tour_completed_at');
        });
    }
};
