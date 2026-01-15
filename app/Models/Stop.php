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

    protected $casts = [
        'lat' => 'float',
        'lng' => 'float',
    ];

    public function lines()
    {
        return $this->belongsToMany(Line::class, 'line_stops');
    }
}

