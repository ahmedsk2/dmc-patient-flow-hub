<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * CUTOVER DATA STEP for W1 specialty scoping — the thing that stops launch day locking clinicians
 * out of consultation entry.
 *
 * From the commit that introduces scoping, a plain clinical user may only book a consult into their
 * OWN specialty, and `specialty_id IS NULL` matches no specialty at all. On the real database that
 * is not an edge case, it is the majority: every active registrar (20) and every active resident
 * (101) has a NULL specialty_id — 121 accounts, between them the authors of 53% of the ~1,283
 * historical consultations — while only the 27 consultants carry one. Without this step those 121
 * users could create nothing and would see an empty workspace apart from rows they personally
 * entered. In a live clinical system that does not corrupt a record, it stops one being written at
 * all, which is how consults end up recorded nowhere.
 *
 * WHAT IT DOES  Grants `can_coordinate_consultations` to every user with no specialty who is not an
 * admin (admins already bypass scoping) and not an observer (read-only is enforced before any
 * capability flag — an observer must never hold one).
 *
 * WHY THIS IS NOT AN ACCESS EXPANSION  Before scoping, EVERY clinical user could see and modify
 * every consultation. The coordinator capability grants exactly that and no more: it deliberately
 * does NOT confer sign-off, delete, or sign-off reversal. So this step preserves the status quo for
 * the unaffiliated accounts while the 27 users who DO have a team get properly scoped — and unlike
 * the status quo it is explicit, per-user, and revocable in Control -> Users.
 *
 * A specialty is never guessed here: inventing a team for 101 residents would put patients in the
 * wrong book, which is worse than the capability. Admins should narrow this by setting real
 * specialties (or revoking the flag) once the org chart is confirmed.
 *
 * Inactive accounts are included deliberately — the flag is inert without a session, and skipping
 * them would silently re-create the lockout the day someone is re-enabled.
 */
return new class extends Migration
{
    /** Roles that must not be granted the flag: 0 = Admin (already exempt), 5 = Observer (read-only). */
    private const EXCLUDED_ROLES = [0, 5];

    public function up(): void
    {
        DB::table('users')
            ->whereNull('specialty_id')
            ->whereNotIn('role', self::EXCLUDED_ROLES)
            ->where('can_coordinate_consultations', false)
            ->update(['can_coordinate_consultations' => true]);
    }

    public function down(): void
    {
        // Revokes the flag from the same population. Note this also clears it from an unaffiliated
        // user an admin granted by hand afterwards — the grant is indistinguishable from this one,
        // and leaving a capability behind on a rollback is the worse of the two errors.
        DB::table('users')
            ->whereNull('specialty_id')
            ->whereNotIn('role', self::EXCLUDED_ROLES)
            ->update(['can_coordinate_consultations' => false]);
    }
};
