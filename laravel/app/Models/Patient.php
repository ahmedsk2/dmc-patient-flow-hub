<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Patient extends Model
{
    protected $guarded = ['id'];

    public function admissions(): HasMany { return $this->hasMany(Admission::class); }
    public function consultations(): HasMany { return $this->hasMany(Consultation::class); }

    public function latestAdmission() { return $this->hasOne(Admission::class)->latestOfMany(); }
}
