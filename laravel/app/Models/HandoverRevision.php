<?php

namespace App\Models;

use App\Casts\EncryptedNarrative;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only handover history — every save adds one row (no updated_at by design). */
class HandoverRevision extends Model
{
    const UPDATED_AT = null;

    protected $guarded = ['id'];

    // DATA-06: `body` encrypted at rest, same as Handover::$casts — see docs/ENCRYPTION-AT-REST.md.
    protected $casts = ['created_at' => 'datetime', 'checkpoints' => 'array', 'body' => EncryptedNarrative::class];

    public function admission(): BelongsTo
    {
        return $this->belongsTo(Admission::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /** Latest revision id for an admission — what a new/just-signed signature pins. */
    public static function latestIdFor(int $admissionId): ?int
    {
        return static::where('admission_id', $admissionId)->max('id');
    }
}
