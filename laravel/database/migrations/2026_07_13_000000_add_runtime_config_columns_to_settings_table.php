<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $t) {
            $t->string('mail_mailer', 20)->nullable();
            $t->string('mail_host', 255)->nullable();
            $t->unsignedSmallInteger('mail_port')->nullable();
            $t->string('mail_encryption', 10)->nullable();
            $t->string('mail_username', 255)->nullable();
            $t->text('mail_password')->nullable();
            $t->string('mail_from_address', 255)->nullable();
            $t->string('mail_from_name', 255)->nullable();
            $t->string('app_timezone', 64)->nullable();
            $t->string('app_name', 120)->nullable();
            $t->string('app_url', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $t) {
            $t->dropColumn([
                'mail_mailer', 'mail_host', 'mail_port', 'mail_encryption', 'mail_username',
                'mail_password', 'mail_from_address', 'mail_from_name',
                'app_timezone', 'app_name', 'app_url',
            ]);
        });
    }
};
