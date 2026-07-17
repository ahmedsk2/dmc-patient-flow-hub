<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('handovers', fn (Blueprint $t) => $t->json('checkpoints')->nullable()->after('body'));
        Schema::table('handover_revisions', fn (Blueprint $t) => $t->json('checkpoints')->nullable()->after('body'));
        Schema::table('notifications', fn (Blueprint $t) => $t->timestamp('resolved_at')->nullable()->after('read_at'));
    }

    public function down(): void
    {
        Schema::table('handovers', fn (Blueprint $t) => $t->dropColumn('checkpoints'));
        Schema::table('handover_revisions', fn (Blueprint $t) => $t->dropColumn('checkpoints'));
        Schema::table('notifications', fn (Blueprint $t) => $t->dropColumn('resolved_at'));
    }
};
