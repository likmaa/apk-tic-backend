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
        return response()->json([
            'daily_tip' => $tip
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'daily_tip' => 'nullable|string'
        ]);

        Setting::updateOrCreate(
            ['key' => 'daily_tip'],
            ['value' => $data['daily_tip']]
        );

        return response()->json(['message' => 'Conseil mis à jour', 'daily_tip' => $data['daily_tip']]);
    }
}
