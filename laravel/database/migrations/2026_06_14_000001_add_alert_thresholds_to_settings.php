<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 1 — Item 4: admin-tunable thresholds for the dashboard alert strip. Additive/optional
 * (every column carries a default), so existing rows and saved data are unaffected.
 *
 * DEFAULTS ARE CONSERVATIVE PLACEHOLDERS — clinicians MUST review them in Control → Settings
 * before production:
 *   alert_overcensus_pct   100  — 100% occupancy is factual over-census
 *   alert_boarding_max       5  — 5 medically-cleared-but-boarding patients is operationally significant
 *   alert_readmit_rate_pct  10  — 10% YTD readmission rate is a commonly used clinical threshold
 *   alert_deaths_delta_pct  50  — a 50% month-over-month mortality rise flags a sharp upturn
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->unsignedInteger('alert_overcensus_pct')->default(100)->after('icu_beds');   // occupancy % at which over-census fires
            $table->unsignedInteger('alert_boarding_max')->default(5)->after('alert_overcensus_pct');     // boarding count above which alert fires
            $table->unsignedInteger('alert_readmit_rate_pct')->default(10)->after('alert_boarding_max');  // YTD readmit rate % threshold
            $table->unsignedInteger('alert_deaths_delta_pct')->default(50)->after('alert_readmit_rate_pct'); // % rise in deaths vs prior month to alert
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn(['alert_overcensus_pct', 'alert_boarding_max', 'alert_readmit_rate_pct', 'alert_deaths_delta_pct']);
        });
    }
};
