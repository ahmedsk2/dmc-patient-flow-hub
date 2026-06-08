<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Consultation extends Model
{
    protected $guarded = ['id'];

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
