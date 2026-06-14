<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Consultation extends Model
{
    // Phase 4 — Item 1: soft-delete (delete() sets deleted_at; global scope hides trashed rows).
    use SoftDeletes;

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
        ];
    }

    public function patient(): BelongsTo { return $this->belongsTo(Patient::class); }
    public function consultant(): BelongsTo { return $this->belongsTo(User::class, 'consultant_id'); }
    public function enteredBy(): BelongsTo { return $this->belongsTo(User::class, 'entered_by'); }

    public function scopeActive(Builder $q): Builder { return $q->whereNull('signoff_date'); }
}
