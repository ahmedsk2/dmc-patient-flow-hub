<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Consultation extends Model
{
    // Phase 4 — Item 1: soft-delete (delete() sets deleted_at; global scope hides trashed rows).
    use SoftDeletes;

    /**
     * Ledger states (W1). W1 only STORES them — the interactive four-state workflow arrives in W2.
     *   new        — logged, not yet seen
     *   active     — the team rounds on it daily and ticks it off
     *   ongoing    — on the books, no daily commitment asserted (also where every OPEN legacy row
     *                was backfilled, so launch day does not invent a worklist)
     *   signed_off — closed with a recorded response
     */
    public const STATUS_NEW = 'new';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_ONGOING = 'ongoing';
    public const STATUS_SIGNED_OFF = 'signed_off';

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        // Consultations feed the dashboard's 6-month consults chart (heavy tier) — bust on write.
        static::saved(fn () => \App\Support\DashboardCache::bust());
        static::deleted(fn () => \App\Support\DashboardCache::bust());
    }

    protected function casts(): array
    {
        return [
            'consultation_date' => 'date',
            'signoff_date' => 'date',
            'indication' => 'array',
            // W1: the REAL timestamps. NULL on every historical row — never fabricated from a DATE.
            'requested_at' => 'datetime',
            'signed_off_at' => 'datetime',
            'response_followup_needed' => 'boolean',
        ];
    }

    public function patient(): BelongsTo { return $this->belongsTo(Patient::class); }
    public function consultant(): BelongsTo { return $this->belongsTo(User::class, 'consultant_id'); }
    public function enteredBy(): BelongsTo { return $this->belongsTo(User::class, 'entered_by'); }

    /** W1 ledger relations. */
    public function followups(): HasMany { return $this->hasMany(ConsultationFollowup::class); }
    public function owningSpecialty(): BelongsTo { return $this->belongsTo(Specialty::class, 'owning_specialty_id'); }
    public function signedOffBy(): BelongsTo { return $this->belongsTo(User::class, 'signed_off_by'); }
    public function admission(): BelongsTo { return $this->belongsTo(Admission::class); }

    /**
     * LEGACY/COMPAT scope, pre-dating the ledger. `signoff_date IS NULL` and `status <>
     * 'signed_off'` (scopeOpen, below) are meant to be kept in lockstep by every W2 status
     * transition — a signoff must never write one without the other, or this scope and scopeOpen
     * silently disagree about which rows are "closed". Nothing in app/ still calls this scope
     * (grep confirms), but statistics/dashboard code outside this model reads signoff_date
     * directly, so it stays until that is migrated over to `status`. Prefer scopeOpen in new code.
     */
    public function scopeActive(Builder $q): Builder { return $q->whereNull('signoff_date'); }

    /**
     * Everything still on the books — the three non-closed states. Kept in lockstep with
     * scopeActive (above) by the invariant `status = 'signed_off' <=> signoff_date IS NOT NULL`;
     * W2's sign-off/reversal code must always write both columns in the same update.
     */
    public function scopeOpen(Builder $q): Builder
    {
        return $q->where('status', '<>', self::STATUS_SIGNED_OFF);
    }

    /**
     * Specialty scoping — the ONE visibility rule, mirroring User::canSeeConsultation() so the list
     * query and the per-row predicate can never drift apart. A scope must never return a row that
     * the matching per-row predicate would refuse.
     *
     * Observers are excluded first, before anything else — the consultations workspace is closed
     * to them entirely (mirrors User::canSeeConsultation()'s own first check).
     *
     * Admins and coordinators see everything (including the Unassigned bucket). Everyone else sees
     * their own specialty's book, plus any consult assigned to them or entered by them — ownership
     * is owning_specialty_id + consultant_id and is INDEPENDENT of entered_by, but you never lose
     * sight of a consult you personally booked. A user with no specialty_id and no coordinator flag
     * therefore sees only their own rows: unit-wide coordinators are given the flag deliberately.
     */
    public function scopeVisibleTo(Builder $q, User $u): Builder
    {
        if ($u->isObserver()) {
            return $q->whereRaw('1 = 0');
        }

        if ($u->isAdmin() || $u->canCoordinateConsultations()) {
            return $q;
        }

        return $q->where(function (Builder $w) use ($u) {
            if ($u->specialty_id !== null) {
                $w->where('owning_specialty_id', $u->specialty_id);
            }
            $w->orWhere('consultant_id', $u->id)->orWhere('entered_by', $u->id);
        });
    }
}
