<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $guarded = ['id'];

    /** The single settings row (created with defaults on first access). */
    public static function current(): self
    {
        return static::firstOrCreate(['id' => 1]);
    }
}
