<?php

namespace App\Models;

use App\Casts\EncryptedNarrative;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/** The CURRENT handover text for an admission (one row, upserted; history in HandoverRevision). */
class Handover extends Model
{
    protected $guarded = ['id'];

    // DATA-06: `body` is free-text clinical narrative — ciphertext at rest (AES-256-CBC under
    // APP_KEY, like users.mfa_secret), plaintext through the model. It is never filtered or
    // sorted by value, so nothing queries it. Any raw read (DB::table / joins) bypasses this cast
    // and must Crypt::decryptString() itself. See docs/ENCRYPTION-AT-REST.md.
    protected $casts = ['checkpoints' => 'array', 'body' => EncryptedNarrative::class];

    public function admission(): BelongsTo
    {
        return $this->belongsTo(Admission::class);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** Same-day transfer gate: was this admission's handover updated TODAY (app TZ)? */
    public static function updatedToday(int $admissionId): bool
    {
        return static::where('admission_id', $admissionId)
            ->whereDate('updated_at', Carbon::today())->exists();
    }
}
