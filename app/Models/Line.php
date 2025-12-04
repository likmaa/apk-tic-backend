<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Line extends Model
{
    protected $fillable = [
        'code',
        'name',
    ];

    public function stops(): BelongsToMany
    {
        return $this->belongsToMany(Stop::class, 'line_stops')
            ->withPivot('position')
            ->orderBy('pivot_position');
    }
}
