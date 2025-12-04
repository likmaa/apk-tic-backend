<?php

namespace App\Http\Controllers;

use App\Models\Line;
use App\Models\LineStop;
use App\Models\Stop;
use Illuminate\Http\Request;

class PassengerLineController extends Controller
{
    public function stops()
    {
        return Stop::orderBy('name')->get(['id', 'code', 'name', 'lat', 'lng']);
    }

    public function lines()
    {
        return Line::with(['stops' => function ($q) {
            $q->orderBy('pivot_position');
        }])->get();
    }

    public function estimate(Request $request)
    {
        $data = $request->validate([
            'line_id'      => ['required', 'integer', 'exists:lines,id'],
            'from_stop_id' => ['required', 'integer', 'exists:stops,id'],
            'to_stop_id'   => ['required', 'integer', 'exists:stops,id'],
        ]);

        $lineStops = LineStop::where('line_id', $data['line_id'])
            ->orderBy('position')
            ->get(['stop_id', 'position']);

        $iFrom = optional($lineStops->firstWhere('stop_id', $data['from_stop_id']))->position;
        $iTo   = optional($lineStops->firstWhere('stop_id', $data['to_stop_id']))->position;

        if ($iFrom === null || $iTo === null) {
            return response()->json([
                'message' => 'Ces arrêts ne font pas partie de cette ligne.',
            ], 422);
        }

        $segments = abs($iTo - $iFrom);
        $unit     = 200; // FCFA par segment
        $price    = $unit * $segments;

        return response()->json([
            'line_id'      => (int) $data['line_id'],
            'from_stop_id' => (int) $data['from_stop_id'],
            'to_stop_id'   => (int) $data['to_stop_id'],
            'segments'     => $segments,
            'unit_price'   => $unit,
            'price'        => $price,
        ]);
    }
}
