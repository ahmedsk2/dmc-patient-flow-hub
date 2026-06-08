<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Icd10 extends Model
{
    protected $table = 'icd10';
    protected $guarded = ['id'];
    public $timestamps = false;
}
