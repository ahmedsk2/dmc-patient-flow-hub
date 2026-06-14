<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 — Item 3: admin-tunable consecutive-failed-login alert threshold. Additive (default 5;
 * 0 disables). When the count of login.failed audit rows for one username in the last 10 minutes
 * EQUALS this value, every active admin gets a security.failed_logins notification.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('failed_login_notify_threshold')->default(5)->after('abs_timeout_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn('failed_login_notify_threshold');
        });
    }
};
