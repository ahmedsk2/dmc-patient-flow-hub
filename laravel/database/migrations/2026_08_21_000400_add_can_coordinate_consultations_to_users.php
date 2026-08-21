<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The consultation-coordinator capability — a fifth per-user flag alongside the existing
 * can_assign / can_add / can_manage / can_modify (2026_06_08_120001_extend_users_table).
 *
 * A coordinator may book a consult into ANY specialty, see all consults and modify all of them.
 * They deliberately may NOT sign off, delete, or reverse a sign-off: signing off asserts the
 * clinical work is done, and that stays with the owning consultant / can_manage / admin.
 *
 * Default FALSE — nobody gains anything when this migration runs; the flag is granted per user in
 * Control -> Users.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('can_coordinate_consultations')->default(false)->after('can_modify');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('can_coordinate_consultations');
        });
    }
};
