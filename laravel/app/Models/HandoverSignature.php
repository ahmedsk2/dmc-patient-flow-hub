<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A receiving consultant's pending/complete acknowledgement of a handover. Created inside the
 * transfer transaction; unsigned rows are voided when superseded by a newer transfer or when
 * the episode is discharged/deleted before signing.
 */
class HandoverSignature extends Model
{
    public $timestamps = false;   // required_at / signed_at / voided_at tell the whole story

    protected $guarded = ['id'];
    protected $casts = ['required_at' => 'datetime', 'signed_at' => 'datetime', 'voided_at' => 'datetime'];

    public function admission(): BelongsTo { return $this->belongsTo(Admission::class); }
    public function fromConsultant(): BelongsTo { return $this->belongsTo(User::class, 'from_consultant_id'); }
    public function toConsultant(): BelongsTo { return $this->belongsTo(User::class, 'to_consultant_id'); }
    public function signedBy(): BelongsTo { return $this->belongsTo(User::class, 'signed_by'); }
    public function revision(): BelongsTo { return $this->belongsTo(HandoverRevision::class, 'revision_id'); }

    /** Unsigned and not voided — awaiting a signature. */
    public function scopePending(Builder $q): Builder { return $q->whereNull('signed_at')->whereNull('voided_at'); }

    /** Void every pending signature for an admission (supersede / discharge / delete). */
    public static function voidPendingFor(int $admissionId): void
    {
        static::where('admission_id', $admissionId)->pending()->update(['voided_at' => now()]);
    }
}
