<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use App\Models\PricingSetting;

class PricingController extends Controller
{
    protected string $cacheKey = 'pricing.config';

    public function get()
    {
        $setting = PricingSetting::query()->first();

        // Si aucune configuration n'existe encore, on en crée une avec des valeurs par défaut
        if (!$setting) {
            $setting = PricingSetting::create([
                'base_fare' => 500,
                'per_km' => 150,
                'per_min' => 50,
                'min_fare' => 1000,
                'zones' => [],
                'peak_hours_enabled' => false,
                'peak_hours_multiplier' => 1.0,
                'peak_hours_start_time' => '17:00:00',
                'peak_hours_end_time' => '20:00:00',
                'platform_commission_pct' => 70,
                'driver_commission_pct' => 20,
                'maintenance_commission_pct' => 10,
            ]);
        }

        $config = [
            'base_fare' => (int) $setting->base_fare,
            'per_km' => (int) $setting->per_km,
            'per_min' => (int) $setting->per_min,
            'min_fare' => (int) $setting->min_fare,
            'zones' => $setting->zones ?? [],
            'peak_hours' => [
                'enabled' => (bool) $setting->peak_hours_enabled,
                'multiplier' => (float) $setting->peak_hours_multiplier,
                'start_time' => substr((string) $setting->peak_hours_start_time, 0, 5),
                'end_time' => substr((string) $setting->peak_hours_end_time, 0, 5),
            ],
            'commission' => [
                'platform_pct' => (int) ($setting->platform_commission_pct ?? 70),
                'driver_pct' => (int) ($setting->driver_commission_pct ?? 20),
                'maintenance_pct' => (int) ($setting->maintenance_commission_pct ?? 10),
            ],
        ];

        return response()->json($config);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'base_fare' => ['sometimes', 'numeric', 'min:0'],
            'per_km' => ['sometimes', 'numeric', 'min:0'],
            'per_min' => ['sometimes', 'numeric', 'min:0'],
            'min_fare' => ['sometimes', 'numeric', 'min:0'],
            'zones' => ['sometimes', 'array'],
            'peak_hours' => ['sometimes', 'array'],
            'peak_hours.enabled' => ['sometimes', 'boolean'],
            'peak_hours.multiplier' => ['sometimes', 'numeric', 'min:0'],
            'peak_hours.start_time' => ['sometimes', 'string'],
            'peak_hours.end_time' => ['sometimes', 'string'],
            'commission' => ['sometimes', 'array'],
            'commission.platform_pct' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'commission.driver_pct' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'commission.maintenance_pct' => ['sometimes', 'integer', 'min:0', 'max:100'],
        ]);
        $setting = PricingSetting::query()->first() ?? new PricingSetting();

        if (array_key_exists('base_fare', $data)) {
            $setting->base_fare = (int) $data['base_fare'];
        }
        if (array_key_exists('per_km', $data)) {
            $setting->per_km = (int) $data['per_km'];
        }
        if (array_key_exists('per_min', $data)) {
            $setting->per_min = (int) $data['per_min'];
        }
        if (array_key_exists('min_fare', $data)) {
            $setting->min_fare = (int) $data['min_fare'];
        }
        if (array_key_exists('zones', $data)) {
            $setting->zones = $data['zones'];
        }
        if (array_key_exists('peak_hours', $data)) {
            $ph = $data['peak_hours'];
            if (array_key_exists('enabled', $ph)) {
                $setting->peak_hours_enabled = (bool) $ph['enabled'];
            }
            if (array_key_exists('multiplier', $ph)) {
                $setting->peak_hours_multiplier = (float) $ph['multiplier'];
            }
            if (array_key_exists('start_time', $ph)) {
                $setting->peak_hours_start_time = $ph['start_time'] . ':00';
            }
            if (array_key_exists('end_time', $ph)) {
                $setting->peak_hours_end_time = $ph['end_time'] . ':00';
            }
        }
        if (array_key_exists('commission', $data)) {
            $comm = $data['commission'];
            if (array_key_exists('platform_pct', $comm)) {
                $setting->platform_commission_pct = (int) $comm['platform_pct'];
            }
            if (array_key_exists('driver_pct', $comm)) {
                $setting->driver_commission_pct = (int) $comm['driver_pct'];
            }
            if (array_key_exists('maintenance_pct', $comm)) {
                $setting->maintenance_commission_pct = (int) $comm['maintenance_pct'];
            }
        }

        $setting->save();

        // Clear commission cache so new rates take effect immediately
        Cache::forget('pricing.commission');

        return $this->get();
    }
}

