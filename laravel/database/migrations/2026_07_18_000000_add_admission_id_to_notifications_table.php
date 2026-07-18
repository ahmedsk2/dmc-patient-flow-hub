<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `handover.incomplete` reminders are correlated to admissions on every board/dashboard load
     * (Admission::handoverPending()). Doing that through the JSON payload is unindexable — a full
     * scan of a table that grows with every notification. This indexed column makes it a plain
     * lookup, and removes the `(string)`-cast fragility the JSON comparison required.
     *
     * Deliberately NO foreign key: notifications outlive the admissions they reference, and the
     * reminder trail is retained for clinical audit (docs/HANDOVER-COMPLIANCE.md).
     */
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $t) {
            $t->unsignedBigInteger('admission_id')->nullable()->after('type')->index();
        });

        // one-off backfill so reminders raised before this migration keep resolving
        DB::statement("UPDATE notifications
            SET admission_id = CAST(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.admission_id')) AS UNSIGNED)
            WHERE admission_id IS NULL AND JSON_EXTRACT(payload, '$.admission_id') IS NOT NULL");
    }

    public function down(): void
    {
        // MySQL drops the column's index with the column
        Schema::table('notifications', fn (Blueprint $t) => $t->dropColumn('admission_id'));
    }
};
