<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Admission extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'admit_date' => 'date',
            'discharge_date' => 'date',
            'medical_discharge_date' => 'date',
            'assigned_on' => 'date',
            'assigned_at' => 'datetime',
            'is_longterm' => 'boolean',
            'is_new_assignment' => 'boolean',
        ];
    }

    public function patient(): BelongsTo { return $this->belongsTo(Patient::class); }
    public function consultant(): BelongsTo { return $this->belongsTo(User::class, 'consultant_id'); }
    public function admittedBy(): BelongsTo { return $this->belongsTo(User::class, 'admitted_by'); }
    public function dischargedBy(): BelongsTo { return $this->belongsTo(User::class, 'discharged_by'); }
    public function diagnoses(): HasMany { return $this->hasMany(AdmissionDiagnosis::class); }

    /** Currently admitted (file not closed). */
    public function scopeActive(Builder $q): Builder { return $q->whereNull('discharge_date'); }

    public function scopeNonIcu(Builder $q): Builder
    {
        return $q->where(fn ($w) => $w->where('current_location', '<>', 'ICU')->orWhereNull('current_location'));
    }

    public function scopeIcu(Builder $q): Builder { return $q->where('current_location', 'ICU'); }

    /** Length of stay in days (discharge or today vs admit). */
    public function lengthOfStay(): ?int
    {
        if (! $this->admit_date) {
            return null;
        }
        $end = $this->discharge_date ?? $this->medical_discharge_date ?? now();
        // Carbon 3 returns a float from diffInDays(); cast explicitly (whole days) to avoid the
        // implicit float->int deprecation that fired once per active patient on the board.
        return (int) $this->admit_date->diffInDays($end);
    }
}
