<?php

namespace App\Models;

use App\Casts\EncryptedNarrative;
use AppCastsncryptedNarrative;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One daily tick on a consultation — the raw material for "seen 8 of 12 today" and for handover.
 *
 * APPEND-ONLY, like handover_revisions: the table has created_at and NO updated_at. We express that
 * with `const UPDATED_AT = null` (rather than $timestamps = false) so Eloquent still stamps
 * created_at automatically and nothing has to manage it by hand. A tick is a fact that happened; it
 * is appended, never edited. The DB's unique(consultation_id, followup_date) makes double-ticking a
 * single day impossible, which is what keeps completeness counts exact.
 */
class ConsultationFollowup extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'followup_date' => 'date',
            'created_at' => 'datetime',
            // DATA-06: the daily note is ciphertext at rest (AES-256-CBC under APP_KEY), plaintext
            // through the model. The handover sheet's latest-follow-up join reads this column RAW
            // (ConsultationsController::handover) and decrypts it explicitly — keep the two in step.
            // See docs/ENCRYPTION-AT-REST.md.
            'note' => EncryptedNarrative::class,
        ];
    }

    public function consultation(): BelongsTo { return $this->belongsTo(Consultation::class); }
    public function author(): BelongsTo { return $this->belongsTo(User::class, 'author_id'); }
}
