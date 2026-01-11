<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Setting;

class SettingsController extends Controller
{
    public function index()
    {
        $tip = Setting::where('key', 'daily_tip')->value('value');
        $linePrice = Setting::where('key', 'tic_line_unit_price')->value('value') ?? 200;
        return response()->json([
            'daily_tip' => $tip,
            'tic_line_unit_price' => $linePrice
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'daily_tip' => 'nullable|string',
            'tic_line_unit_price' => 'nullable|numeric'
        ]);

        if (isset($data['daily_tip'])) {
            Setting::updateOrCreate(
                ['key' => 'daily_tip'],
                ['value' => $data['daily_tip']]
            );
        }

        if (isset($data['tic_line_unit_price'])) {
            Setting::updateOrCreate(
                ['key' => 'tic_line_unit_price'],
                ['value' => $data['tic_line_unit_price']]
            );
        }

        return response()->json([
            'message' => 'Paramètres mis à jour',
            'daily_tip' => $data['daily_tip'] ?? null,
            'tic_line_unit_price' => $data['tic_line_unit_price'] ?? null
        ]);
    }
}
