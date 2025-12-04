<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Stop extends Model
{
    protected $fillable = [
        'code',
        'name',
        'type',
        'lat',
        'lng',
    ];
}
