<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * (role walkthrough 2026-09-25, E2) A consultant-changing internal-specialty transfer closes
     * the old episode and opens a new one under the receiving consultant — but the receiving
     * consultant's HandoverSignature is bound to the OLD (closing) episode, while the persistent
     * `handover.incomplete` reminder that drives "Needs handover" / the bell is bound to the NEW
     * episode (PatientActionController::transferSpecialty, deliberately — see its comment). The
     * outgoing consultant can only write text on the old episode ("My outgoing" → Update text),
     * so without a link back to the new episode that save could never resolve the new episode's
     * reminder. This column is that reliable link, set once at transfer time; HandoverController
     * ::save() walks it one hop forward to also resolve the successor's open reminders.
     *
     * Nullable, additive, backfills nothing: every admission before this migration has no
     * predecessor recorded, which is correct — there is nothing to resolve retroactively.
     */
    public function up(): void
    {
        Schema::table('admissions', function (Blueprint $t) {
            $t->foreignId('predecessor_admission_id')->nullable()->after('legacy_id')
                ->constrained('admissions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('admissions', function (Blueprint $t) {
            $t->dropConstrainedForeignId('predecessor_admission_id');
        });
    }
};
