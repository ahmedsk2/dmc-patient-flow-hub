<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 — Item 5 (tamper-evident hash chain): each audit row carries the previous row's hash
 * (prev_hash) and a sha256 of its own canonical content (row_hash). `php artisan audit:verify`
 * walks the chain and flags the first broken link. Both columns are NULLABLE so existing rows
 * survive the migration — the chain starts from the first post-migration row (or run a backfill
 * before production deploy). Additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_log', function (Blueprint $table) {
            $table->string('prev_hash', 64)->nullable()->after('ip');      // null for the first row
            $table->string('row_hash', 64)->nullable()->after('prev_hash'); // sha256 of canonical form
        });
    }

    public function down(): void
    {
        Schema::table('audit_log', function (Blueprint $table) {
            $table->dropColumn(['prev_hash', 'row_hash']);
        });
    }
};
