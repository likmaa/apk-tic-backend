<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PricingSetting extends Model
{
    use HasFactory;

    protected $table = 'pricing_settings';

    protected $fillable = [
        'base_fare',
        'per_km',
        'per_min',
        'min_fare',
        'peak_hours_enabled',
        'peak_hours_multiplier',
        'peak_hours_start_time',
        'peak_hours_end_time',
        'zones',
    ];

    protected $casts = [
        'peak_hours_enabled' => 'boolean',
        'peak_hours_multiplier' => 'float',
        'zones' => 'array',
    ];
}
