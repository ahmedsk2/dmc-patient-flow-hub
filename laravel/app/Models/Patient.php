<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Patient extends Model
{
    // Phase 4 — Item 9: soft-delete. The patient-merge tool re-points a SOURCE patient's whole
    // clinical history onto a canonical TARGET, then delete()s the empty source — which now sets
    // deleted_at (no physical DELETE, so admissions.patient_id's cascadeOnDelete never fires) and the
    // global scope hides the trashed source from the board, the registry and the duplicates finder.
    // Recovery is withTrashed()->restore(). Raw DB::table('patients') analytics are unaffected: a
    // merged-away source has no live admissions/consultations pointing at it.
    use SoftDeletes;

    protected $guarded = ['id'];

    public function admissions(): HasMany
    {
        return $this->hasMany(Admission::class);
    }

    public function consultations(): HasMany
    {
        return $this->hasMany(Consultation::class);
    }

    public function latestAdmission()
    {
        return $this->hasOne(Admission::class)->latestOfMany();
    }
}
