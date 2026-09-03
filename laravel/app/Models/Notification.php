<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Lightweight in-app notification (bell feed) — currently handover.* events only. */
class Notification extends Model
{
    public $timestamps = false;          // created_at only (DB default useCurrent)

    protected $guarded = ['id'];

    protected $casts = ['payload' => 'array', 'read_at' => 'datetime', 'resolved_at' => 'datetime', 'created_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
