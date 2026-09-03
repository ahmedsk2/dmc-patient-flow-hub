<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Phase 3 — §3.3: a recipient of the scheduled monthly report email. */
class ReportRecipient extends Model
{
    public $timestamps = false; // created_at only (DB default useCurrent)

    protected $guarded = ['id'];

    protected $casts = ['active' => 'boolean', 'created_at' => 'datetime'];

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by_id');
    }
}
