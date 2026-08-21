<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One-time backfill of the ~1,283 historical consultations into the ledger model.
 *
 * STATUS
 *   signoff_date NOT NULL -> signed_off
 *   signoff_date IS NULL  -> ONGOING, *** never active ***
 *
 *   Why ongoing: mapping open legacy rows to `active` would fabricate a daily follow-up obligation
 *   for every one of them, so on launch day every team would open a huge false "must be seen today"
 *   list. This project already suffered exactly that with the handover backlog. `ongoing` states
 *   the truth — on the books, no daily commitment asserted — and teams promote to `active`
 *   deliberately, one consult at a time.
 *
 * OWNING SPECIALTY
 *   Resolved by case-insensitive match of `to_service` against `specialties.name`, restricted to
 *   INTERNAL specialties (is_external = 0) — the same shape as the precedent data-fix migration
 *   2026_06_11_000002_resolve_numeric_consultation_to_service. External services and free text
 *   (e.g. "Some Outside Clinic") match nothing and stay NULL: the Unassigned bucket. A specialty is
 *   NEVER guessed — a wrong owner would hide a patient from the team that is actually seeing them.
 *
 * TIMESTAMPS
 *   requested_at / signed_off_at are LEFT NULL. Both legacy columns are DATE-only; deriving a
 *   datetime would manufacture precision that never existed. Turnaround metrics cover cutover
 *   onward and must exclude NULLs explicitly.
 *
 * Raw query-builder / raw SQL is used deliberately: it bypasses the model's SoftDeletes global
 * scope, so soft-deleted historical rows are backfilled too and stay consistent if restored.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('consultations')->whereNotNull('signoff_date')->update(['status' => 'signed_off']);
        DB::table('consultations')->whereNull('signoff_date')->update(['status' => 'ongoing']);

        DB::statement("
            UPDATE consultations c
            JOIN specialties s
              ON LOWER(TRIM(s.name)) = LOWER(TRIM(c.to_service))
             AND s.is_external = 0
            SET c.owning_specialty_id = s.id
            WHERE c.owning_specialty_id IS NULL
              AND c.to_service IS NOT NULL
              AND TRIM(c.to_service) <> ''
        ");
    }

    public function down(): void
    {
        // Data backfill — intentionally NOT reversed here. The columns it writes did not exist
        // before 2026_08_21_000100; rolling THAT migration back drops them entirely, which is the
        // real reversal. Re-setting status to its default here would be a destructive no-value
        // write over rows the team may already have moved by hand.
    }
};
