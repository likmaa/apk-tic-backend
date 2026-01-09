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
        'platform_commission_pct',
        'driver_commission_pct',
        'maintenance_commission_pct',
        'weather_multiplier',
        'weather_mode_enabled',
        'night_multiplier',
        'night_start_time',
        'night_end_time',
        'stop_rate_per_min',
    ];

    protected $casts = [
        'peak_hours_enabled' => 'boolean',
        'peak_hours_multiplier' => 'float',
        'zones' => 'array',
        'platform_commission_pct' => 'integer',
        'driver_commission_pct' => 'integer',
        'maintenance_commission_pct' => 'integer',
        'weather_multiplier' => 'float',
        'weather_mode_enabled' => 'boolean',
        'night_multiplier' => 'float',
        'stop_rate_per_min' => 'integer',
    ];
}

