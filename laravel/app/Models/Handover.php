<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/** The CURRENT handover text for an admission (one row, upserted; history in HandoverRevision). */
class Handover extends Model
{
    protected $guarded = ['id'];

    public function admission(): BelongsTo { return $this->belongsTo(Admission::class); }
    public function updatedBy(): BelongsTo { return $this->belongsTo(User::class, 'updated_by'); }

    /** Same-day transfer gate: was this admission's handover updated TODAY (app TZ)? */
    public static function updatedToday(int $admissionId): bool
    {
        return static::where('admission_id', $admissionId)
            ->whereDate('updated_at', Carbon::today())->exists();
    }
}
