<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Ride;
use App\Events\RideCancelled;
use App\Events\RideRequested;
use App\Events\RideAccepted;
use App\Models\User;
use App\Services\FcmService;

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

    public function cancel(Request $request, int $id)
    {
        // ... (existing code remains SAME)
        \Log::info('Admin::cancel called', ['ride_id' => $id, 'admin_id' => auth()->id()]);

        $ride = Ride::findOrFail($id);

        if (in_array($ride->status, ['completed', 'cancelled'])) {
            \Log::warning('Admin::cancel failed - already completed or cancelled', ['ride_id' => $id, 'status' => $ride->status]);
            return response()->json(['message' => 'Impossible d\'annuler une course déjà terminée ou annulée.'], 422);
        }

        $ride->status = 'cancelled';
        $ride->cancelled_at = now();
        $ride->cancellation_reason = 'Annulation par l\'administrateur';
        $ride->save();

        \Log::info('Admin::cancel success', ['ride_id' => $id]);

        broadcast(new RideCancelled($ride, 'admin', auth()->user()));

        return response()->json(['ok' => true, 'message' => 'Course annulée avec succès.']);
    }

    /**
     * Crée manuellement une course par l'administrateur.
     */
    public function store(Request $request)
    {
        try {
            $data = $request->validate([
                'pickup_address' => 'required|string|max:255',
                'dropoff_address' => 'required|string|max:255',
                'fare_amount' => 'required|numeric|min:1',
                'passenger_name' => 'nullable|string|max:255',
                'passenger_phone' => 'nullable|string|max:255',
                'vehicle_type' => 'nullable|string|in:standard,vip',
                'has_baggage' => 'nullable|boolean',
            ]);

            $ride = Ride::create([
                'rider_id' => $request->user()->id,
                'status' => 'requested',
                'fare_amount' => (int) $data['fare_amount'],
                'pickup_address' => $data['pickup_address'],
                'dropoff_address' => $data['dropoff_address'],
                'passenger_name' => $data['passenger_name'],
                'passenger_phone' => $data['passenger_phone'],
                'vehicle_type' => $data['vehicle_type'] ?? 'standard',
                'has_baggage' => $data['has_baggage'] ?? false,
                'currency' => 'XOF',
                'payment_method' => 'cash',
                'declined_driver_ids' => [],
            ]);

            broadcast(new RideRequested($ride));

            return response()->json([
                'ok' => true,
                'ride' => $ride
            ], 201);
        } catch (\Exception $e) {
            \Log::error("Manual Ride Creation Error: " . $e->getMessage(), [
                'request' => $request->all(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'message' => 'Erreur lors de la création de la course.',
                'error' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * Assigne manuellement un chauffeur à une course.
     */
    public function assign(Request $request, int $id)
    {
        $data = $request->validate([
            'driver_id' => 'required|exists:users,id',
        ]);

        $ride = Ride::findOrFail($id);
        $driver = User::findOrFail($data['driver_id']);

        if (!$driver->isDriver()) {
            return response()->json(['message' => 'L\'utilisateur n\'est pas un chauffeur.'], 422);
        }

        if ($ride->status !== 'requested') {
            return response()->json(['message' => 'La course n\'est plus disponible pour l\'assignation.'], 422);
        }

        $ride->status = 'accepted';
        $ride->driver_id = $driver->id;
        $ride->offered_driver_id = $driver->id;
        $ride->accepted_at = now();
        $ride->save();

        broadcast(new RideAccepted($ride->load('driver')));

        // Notification FCM au passager si mobile (ou info dans le canal socket)
        try {
            if ($ride->rider_id || $ride->passenger_phone) {
                $fcm = app(FcmService::class);
                // Si on a un rider_id, on notifie l'user
                if ($ride->rider_id) {
                    $passenger = User::find($ride->rider_id);
                    if ($passenger) {
                        $fcm->sendToUser(
                            $passenger,
                            "Course assignée !",
                            "Le support a assigné {$driver->name} pour votre course.",
                            ['ride_id' => (string) $ride->id, 'type' => 'ride_accepted']
                        );
                    }
                }
            }
        } catch (\Exception $e) {
            \Log::error("FCM Manual Assignment Notification Error: " . $e->getMessage());
        }

        return response()->json([
            'ok' => true,
            'message' => 'Chauffeur assigné avec succès.',
            'ride' => $ride
        ]);
    }
}
