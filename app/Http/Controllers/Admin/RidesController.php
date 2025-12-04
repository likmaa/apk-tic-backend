<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Ride;

class RidesController extends Controller
{
    public function index(Request $request)
    {
        $status = $request->query('status');
        $driverId = $request->query('driver_id');
        $passengerId = $request->query('passenger_id');
        $reference = $request->query('reference');
        $from = $request->query('from');
        $to = $request->query('to');
        $perPage = (int) $request->query('per_page', 20);
        $perPage = min(max($perPage, 1), 100);

        $query = Ride::query()
            ->with([
                'driver:id,name,phone,vehicle_number',
                'rider:id,name,phone',
            ])
            ->orderByDesc('id');

        if ($status) {
            $query->where('status', $status);
        }
        if ($driverId) {
            $query->where('driver_id', $driverId);
        }
        if ($passengerId) {
            $query->where('rider_id', $passengerId);
        }
        if ($reference) {
            $query->where('id', $reference);
        }
        if ($from) {
            $query->whereDate('created_at', '>=', $from);
        }
        if ($to) {
            $query->whereDate('created_at', '<=', $to);
        }

        $rides = $query->paginate($perPage);

        $rides->getCollection()->transform(function (Ride $ride) {
            return [
                'id' => $ride->id,
                'status' => $ride->status,
                'fare' => (int) ($ride->fare_amount ?? 0),
                'distance_m' => (int) ($ride->distance_m ?? 0),
                'duration_s' => (int) ($ride->duration_s ?? 0),
                'pickup_address' => $ride->pickup_address,
                'dropoff_address' => $ride->dropoff_address,
                'created_at' => $ride->created_at,
                'accepted_at' => $ride->accepted_at,
                'started_at' => $ride->started_at,
                'completed_at' => $ride->completed_at,
                'cancelled_at' => $ride->cancelled_at,
                'declined_driver_ids' => $ride->declined_driver_ids ?? [],
                'driver' => $ride->driver ? [
                    'id' => $ride->driver->id,
                    'name' => $ride->driver->name,
                    'phone' => $ride->driver->phone,
                    'vehicle_number' => $ride->driver->vehicle_number,
                ] : null,
                'passenger' => $ride->rider ? [
                    'id' => $ride->rider->id,
                    'name' => $ride->rider->name,
                    'phone' => $ride->rider->phone,
                ] : null,
            ];
        });

        return response()->json($rides);
    }

    public function byPassenger(Request $request, int $id)
    {
        $status = $request->query('status');
        $perPage = (int) $request->query('per_page', 20);

        $query = Ride::query()
            ->leftJoin('users as drivers', 'drivers.id', '=', 'rides.driver_id')
            ->where('rider_id', $id)
            ->select('rides.*', 'drivers.name as driver_name');
        if ($status) {
            $query->where('status', $status);
        }

        $rides = $query->orderByDesc('rides.id')->paginate($perPage);

        return response()->json($rides);
    }

    public function statusBreakdown(Request $request)
    {
        $statuses = Ride::select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->orderBy('status')
            ->get();

        $declineData = Ride::whereNotNull('declined_driver_ids')
            ->select('id', 'pickup_address', 'dropoff_address', 'declined_driver_ids', 'updated_at')
            ->orderByDesc('updated_at')
            ->get();

        $totalDeclines = $declineData->reduce(function (int $carry, Ride $ride) {
            return $carry + count($ride->declined_driver_ids ?? []);
        }, 0);

        $recentDeclines = $declineData->map(function (Ride $ride) {
            return [
                'ride_id' => $ride->id,
                'declined_count' => count($ride->declined_driver_ids ?? []),
                'pickup_address' => $ride->pickup_address,
                'dropoff_address' => $ride->dropoff_address,
                'updated_at' => $ride->updated_at,
            ];
        })->take(5);

        $cancelledLast24h = Ride::where('status', 'cancelled')
            ->where('updated_at', '>=', now()->subDay())
            ->count();

        $cancelledReasons = Ride::where('status', 'cancelled')
            ->select('cancellation_reason', DB::raw('count(*) as total'))
            ->groupBy('cancellation_reason')
            ->orderByDesc('total')
            ->take(5)
            ->get();

        return response()->json([
            'statuses' => $statuses,
            'declines' => [
                'total_driver_refusals' => $totalDeclines,
                'recent' => $recentDeclines,
            ],
            'cancellations' => [
                'last_24h' => $cancelledLast24h,
                'top_reasons' => $cancelledReasons,
            ],
        ]);
    }
}
