<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Specialty extends Model
{
    protected $guarded = ['id'];
    public $timestamps = true;
    protected $casts = ['is_subspecialty' => 'boolean', 'is_external' => 'boolean'];
}
