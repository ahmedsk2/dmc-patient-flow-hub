<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-07-11 hardening: restore a UNIQUE index on `users.email` — defense-in-depth for the
 * login-adjacent identity used by password-reset and email verification.
 *
 * The default create_users migration had this index; extend_users dropped it because legacy
 * `members` may share or lack emails. We reinstate it safely: NULL out any pre-existing duplicate
 * (keeping the lowest id) using the column's OWN collation so the survivors are exactly the rows
 * the index will accept. NULL emails stay allowed and non-unique (MySQL treats NULLs as distinct),
 * preserving the legacy "no address on file" shape. `legacy:import` de-duplicates member emails the
 * same way, so a fresh re-import never violates this index.
 */
return new class extends Migration
{
    public function up(): void
    {
        // GROUP BY email uses the column collation (utf8mb4_unicode_ci) — the same equality the
        // unique index enforces — so this catches precisely the collisions it would reject.
        $duplicateEmails = DB::table('users')
            ->whereNotNull('email')
            ->groupBy('email')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('email');

        foreach ($duplicateEmails as $email) {
            $ids = DB::table('users')->where('email', $email)->orderBy('id')->pluck('id');
            $ids->shift();   // the lowest id keeps the address
            if ($ids->isNotEmpty()) {
                DB::table('users')->whereIn('id', $ids->all())->update(['email' => null]);
            }
        }

        Schema::table('users', function (Blueprint $table) {
            $table->unique('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['email']);
        });
    }
};
