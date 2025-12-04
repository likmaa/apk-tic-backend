<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LineStop extends Model
{
    protected $table = 'line_stops';

    protected $fillable = [
        'line_id',
        'stop_id',
        'position',
    ];
}
