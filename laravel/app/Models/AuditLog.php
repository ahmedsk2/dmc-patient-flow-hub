<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * Append-only audit row. Phase 2 — Item 5 adds a tamper-evident hash chain: each row stores the
 * previous row's hash (prev_hash) + a sha256 of its own canonical content (row_hash), and the
 * model refuses ORM-level update()/delete() so a code path can never silently rewrite history.
 * `php artisan audit:verify` walks the chain. Every write goes through App\Support\Audit::log,
 * which calls create() here — so every row participates in the chain automatically.
 */
class AuditLog extends Model
{
    protected $table = 'audit_log';
    public $timestamps = false;          // created_at only (DB default useCurrent)
    protected $guarded = ['id'];
    protected $casts = ['details' => 'array', 'created_at' => 'datetime'];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * Deterministic JSON of the fixed fields in alphabetical key order — the bytes that get
     * sha256'd. Including `id` and `prev_hash` chains each row to its predecessor; recomputing
     * this and comparing to the stored row_hash detects any after-the-fact edit (Item 5 verify).
     */
    public function canonical(): string
    {
        return json_encode([
            'action' => $this->action,
            'actor_id' => $this->actor_id,
            'actor_name' => $this->actor_name,
            'created_at' => $this->created_at?->toIso8601String(),
            'details' => $this->details,   // already array-cast
            'entity_id' => $this->entity_id,
            'entity_type' => $this->entity_type,
            'id' => $this->id,
            'ip' => $this->ip,
            'prev_hash' => $this->prev_hash,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    protected static function booted(): void
    {
        // Set prev_hash from the latest existing row's row_hash, serialised under a row lock so two
        // concurrent inserts can't both claim the same predecessor. Audit writes are infrequent
        // (< 1/s) and off the clinical critical path, so the lock scope is negligible.
        static::creating(function (self $row) {
            $row->prev_hash = DB::table('audit_log')->lockForUpdate()->orderByDesc('id')->value('row_hash');
            // id is assigned by the INSERT; the final row_hash is stamped in the created hook below.
        });

        // id now exists → compute the chained hash and write it back. The direct DB::table()->update()
        // bypasses the model so it does NOT trip the immutability guard below.
        static::created(function (self $row) {
            // created_at is set by the DB default (useCurrent) and is NOT populated on the in-memory
            // instance after insert — read it back so it participates in the canonical form (the
            // verify walk reads it from the DB; the two MUST agree). Done without a full model
            // refresh (which would re-fire events) via a direct value read.
            if ($row->created_at === null) {
                $row->created_at = DB::table('audit_log')->where('id', $row->id)->value('created_at');
            }
            $hash = hash('sha256', $row->canonical());
            DB::table('audit_log')->where('id', $row->id)->update(['row_hash' => $hash]);
            $row->row_hash = $hash;   // keep the in-memory instance consistent
        });

        // Immutability guard: an audit row is append-only. A DBA editing the table directly bypasses
        // this (acceptable) — the chain walk detects that. The ORM path is sealed.
        static::updating(function () {
            throw new \RuntimeException('AuditLog rows are immutable — audit_log is append-only.');
        });
        static::deleting(function () {
            throw new \RuntimeException('AuditLog rows cannot be deleted via the ORM.');
        });
    }
}
