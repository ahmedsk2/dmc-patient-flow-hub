<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extend the default users table with the clinical/role fields the app needs
 * (clean names; replaces the legacy `members` shape: position -> role, etc.).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username')->nullable()->unique()->after('id');   // login name (was member_name)
            $table->string('full_name')->nullable()->after('name');
            // role: 0=Admin, 2=Registrar, 3=Consultant, 4=Resident, 5=Observer (legacy `position`)
            $table->unsignedTinyInteger('role')->default(5)->index()->after('full_name');
            $table->foreignId('specialty_id')->nullable()->after('role');
            $table->boolean('active')->default(true)->index()->after('specialty_id');
            $table->boolean('on_service')->default(false)->after('active');
            // capability flags
            $table->boolean('can_assign')->default(false)->after('on_service');
            $table->boolean('can_add')->default(false)->after('can_assign');
            $table->boolean('can_manage')->default(false)->after('can_add');
            $table->boolean('can_modify')->default(false)->after('can_manage');
            // MFA (TOTP)
            $table->string('mfa_secret')->nullable()->after('password');
            $table->text('mfa_recovery_codes')->nullable()->after('mfa_secret');
            $table->dateTime('mfa_enrolled_at')->nullable()->after('mfa_recovery_codes');
            $table->date('pass_exp_date')->nullable();
            $table->unsignedBigInteger('legacy_id')->nullable()->index();    // original members.member_id
        });

        // email is NOT NULL + unique in the default migration, but legacy members may share/lack emails.
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['email']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'username', 'full_name', 'role', 'specialty_id', 'active', 'on_service',
                'can_assign', 'can_add', 'can_manage', 'can_modify',
                'mfa_secret', 'mfa_recovery_codes', 'mfa_enrolled_at', 'pass_exp_date', 'legacy_id',
            ]);
        });
    }
};
