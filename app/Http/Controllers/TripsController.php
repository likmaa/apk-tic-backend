<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Events\DriverLocationUpdated;
use App\Events\RideAccepted;
use App\Events\RideRequested;
use App\Events\RideDeclined;
use App\Events\RideCancelled;
use App\Events\RideStarted;
use App\Events\RideCompleted;

use App\Models\PricingSetting;

use App\Models\Ride;
use App\Models\User;

class TripsController extends Controller
{
    public function estimate(Request $request)
    {
        $data = $request->validate([
            'pickup.lat'   => ['required','numeric','between:-90,90'],
            'pickup.lng'   => ['required','numeric','between:-180,180'],
            'dropoff.lat'  => ['required','numeric','between:-90,90'],
            'dropoff.lng'  => ['required','numeric','between:-180,180'],
            'distance_m'   => ['required','numeric','min:1'],
            'duration_s'   => ['required','numeric','min:1'],
            'vehicle_type' => ['nullable','string','in:standard,vip'],
        ]);

        $vehicleType = $request->input('vehicle_type', 'standard');

        $distance = (float) $request->input('distance_m');
        $duration = (float) $request->input('duration_s');

        $km = $distance / 1000.0;
        $min = $duration / 60.0;
        $pricing = Cache::remember('pricing.config', 60, function () {
            $setting = PricingSetting::query()->first();

            if (!$setting) {
                return [
                    'base_fare' => 500,
                    'per_km' => 250,
                    'per_min' => 50,
                    'min_fare' => 1000,
                    'peak_hours' => [
                        'enabled' => false,
                        'multiplier' => 1.0,
                        'start_time' => '17:00',
                        'end_time' => '20:00',
                    ],
                ];
            }

            return [
                'base_fare' => (int) $setting->base_fare,
                'per_km' => (int) $setting->per_km,
                'per_min' => (int) $setting->per_min,
                'min_fare' => (int) $setting->min_fare,
                'peak_hours' => [
                    'enabled' => (bool) $setting->peak_hours_enabled,
                    'multiplier' => (float) $setting->peak_hours_multiplier,
                    'start_time' => substr((string) $setting->peak_hours_start_time, 0, 5),
                    'end_time' => substr((string) $setting->peak_hours_end_time, 0, 5),
                ],
            ];
        });
        $base = (float) ($pricing['base_fare'] ?? 500);        // tarif de base
        $perKm = (float) ($pricing['per_km'] ?? 250);          // tarif par km
        $perMin = (float) ($pricing['per_min'] ?? 50);         // tarif par minute
        $price = $base + ($perKm * $km) + ($perMin * $min);

        // Multiplicateur VIP
        if ($vehicleType === 'vip') {
            $price *= 1.5; // +50% pour le VIP
        }

        $peak = $pricing['peak_hours'] ?? null;
        if (is_array($peak) && !empty($peak['enabled'])) {
            $multiplier = (float) ($peak['multiplier'] ?? 1.0);
            $start = $peak['start_time'] ?? '17:00';
            $end = $peak['end_time'] ?? '20:00';
            $nowTime = now()->format('H:i');

            if ($start <= $end) {
                $inRange = $nowTime >= $start && $nowTime <= $end;
            } else {
                $inRange = $nowTime >= $start || $nowTime <= $end;
            }

            if ($inRange && $multiplier > 0) {
                $price *= $multiplier;
            }
        }

        $price = max($minFare, (int) round($price, 0));

        return response()->json([
            'price' => $price,
            'currency' => 'XOF',
            'eta_s' => (int) round($duration),
            'distance_m' => (int) round($distance),
        ]);
    }

    public function estimateFromCoords(Request $request)
    {
        $data = $request->validate([
            'pickup.lat'   => ['required','numeric','between:-90,90'],
            'pickup.lng'   => ['required','numeric','between:-180,180'],
            'dropoff.lat'  => ['required','numeric','between:-90,90'],
            'dropoff.lng'  => ['required','numeric','between:-180,180'],
            'vehicle_type' => ['nullable','string','in:standard,vip'],
        ]);

        $vehicleType = $request->input('vehicle_type', 'standard');

        $pickLat = (float) $request->input('pickup.lat');
        $pickLng = (float) $request->input('pickup.lng');
        $dropLat = (float) $request->input('dropoff.lat');
        $dropLng = (float) $request->input('dropoff.lng');

        // Utilise OSRM public pour récupérer distance et durée + géométrie
        $url = 'https://router.project-osrm.org/route/v1/driving/' . $pickLng . ',' . $pickLat . ';' . $dropLng . ',' . $dropLat . '?overview=full&geometries=geojson';
        $resp = Http::timeout(8)->get($url);
        if (!$resp->ok()) {
            return response()->json(['message' => 'Routing failed', 'status' => $resp->status()], 502);
        }
        $json = $resp->json();
        $route = $json['routes'][0] ?? null;
        if (!$route) {
            return response()->json(['message' => 'No route'], 422);
        }
        $distance = (float) ($route['distance'] ?? 0);   // en mètres
        $duration = (float) ($route['duration'] ?? 0);   // en secondes
        $geometry = $route['geometry'] ?? null;

        $km = $distance / 1000.0;
        $min = $duration / 60.0;
        $pricing = Cache::remember('pricing.config', 60, function () {
            $setting = PricingSetting::query()->first();

            if (!$setting) {
                return [
                    'base_fare' => 500,
                    'per_km' => 225,
                    'per_min' => 50,
                    'min_fare' => 500,
                    'peak_hours' => [
                        'enabled' => false,
                        'multiplier' => 1.0,
                        'start_time' => '17:00',
                        'end_time' => '20:00',
                    ],
                ];
            }

            return [
                'base_fare' => (int) $setting->base_fare,
                'per_km' => (int) $setting->per_km,
                'per_min' => (int) $setting->per_min,
                'min_fare' => (int) $setting->min_fare,
                'peak_hours' => [
                    'enabled' => (bool) $setting->peak_hours_enabled,
                    'multiplier' => (float) $setting->peak_hours_multiplier,
                    'start_time' => substr((string) $setting->peak_hours_start_time, 0, 5),
                    'end_time' => substr((string) $setting->peak_hours_end_time, 0, 5),
                ],
            ];
        });
        $base = (float) ($pricing['base_fare'] ?? 500);
        $perKm = (float) ($pricing['per_km'] ?? 250);
        $perMin = (float) ($pricing['per_min'] ?? 50);
        $price = $base + ($perKm * $km) + ($perMin * $min);

        // Multiplicateur VIP
        if ($vehicleType === 'vip') {
            $price *= 1.5; // +50% pour le VIP
        }

        $peak = $pricing['peak_hours'] ?? null;
        if (is_array($peak) && !empty($peak['enabled'])) {
            $multiplier = (float) ($peak['multiplier'] ?? 1.0);
            $start = $peak['start_time'] ?? '17:00';
            $end = $peak['end_time'] ?? '20:00';
            $nowTime = now()->format('H:i');

            if ($start <= $end) {
                $inRange = $nowTime >= $start && $nowTime <= $end;
            } else {
                $inRange = $nowTime >= $start || $nowTime <= $end;
            }

            if ($inRange && $multiplier > 0) {
                $price *= $multiplier;
            }
        }

        $minFare = (float) ($pricing['min_fare'] ?? 500);
        $price = max($minFare, (int) round($price, 0));

        return response()->json([
            'price' => $price,
            'currency' => 'XOF',
            'eta_s' => (int) round($duration),
            'distance_m' => (int) round($distance),
            'geometry' => $geometry,
            'source' => 'mapbox',
        ]);
    }

    /**
     * Crée une course TIC à partir d'un prix déjà calculé (par ex. /passenger/lines/estimate).
     * On enregistre surtout les adresses et le montant, en laissant la logique d'assignation chauffeur évoluer plus tard.
     */
    public function requestTicRide(Request $request)
    {
        /** @var User|null $user */
        $user = Auth::user();
        if (!$user || !$user->isPassenger()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'pickup_label'  => ['required', 'string', 'max:255'],
            'dropoff_label' => ['required', 'string', 'max:255'],
            'price'         => ['required', 'numeric', 'min:1'],
            'pickup_lat'    => ['nullable', 'numeric', 'between:-90,90'],
            'pickup_lng'    => ['nullable', 'numeric', 'between:-180,180'],
            'dropoff_lat'   => ['nullable', 'numeric', 'between:-90,90'],
            'dropoff_lng'   => ['nullable', 'numeric', 'between:-180,180'],
            'passenger_name' => ['nullable', 'string', 'max:255'],
            'passenger_phone' => ['nullable', 'string', 'max:255'],
            'vehicle_type'   => ['nullable', 'string', 'in:standard,vip'],
            'has_baggage'    => ['nullable', 'boolean'],
        ]);

        $pickupLat = $data['pickup_lat'] ?? null;
        $pickupLng = $data['pickup_lng'] ?? null;
        $dropoffLat = $data['dropoff_lat'] ?? null;
        $dropoffLng = $data['dropoff_lng'] ?? null;

        $ride = Ride::create([
            'rider_id' => $user->id,
            'driver_id' => null,
            'offered_driver_id' => null,
            'status' => 'requested',
            'fare_amount' => (int) $data['price'],
            'commission_amount' => 0,
            'driver_earnings_amount' => 0,
            'currency' => 'XOF',
            'distance_m' => null,
            'duration_s' => null,
            'pickup_lat' => $pickupLat,
            'pickup_lng' => $pickupLng,
            'pickup_address' => $data['pickup_label'],
            'dropoff_lat' => $dropoffLat,
            'dropoff_lng' => $dropoffLng,
            'dropoff_address' => $data['dropoff_label'],
            'passenger_name' => $data['passenger_name'] ?? null,
            'passenger_phone' => $data['passenger_phone'] ?? null,
            'vehicle_type' => $data['vehicle_type'] ?? 'standard',
            'has_baggage' => $data['has_baggage'] ?? false,
            'declined_driver_ids' => [],
        ]);

        // Si on a des coordonnées pickup, on tente une assignation immédiate comme pour create()
        if ($ride->pickup_lat !== null && $ride->pickup_lng !== null) {
            // True Broadcast: on laisse offered_driver_id à null pour que tous les chauffeurs éligibles voient la course
            broadcast(new RideRequested($ride));
        }

        return response()->json([
            'id' => $ride->id,
            'status' => $ride->status,
            'price' => $ride->fare_amount,
            'currency' => $ride->currency,
            'passenger_name' => $ride->passenger_name,
            'passenger_phone' => $ride->passenger_phone,
            'vehicle_type' => $ride->vehicle_type,
            'has_baggage' => (bool)$ride->has_baggage,
        ], 201);
    }

    public function create(Request $request)
    {
        /** @var User|null $user */
        $user = Auth::user();
        $data = $request->validate([
            'pickup.lat'   => ['required','numeric','between:-90,90'],
            'pickup.lng'   => ['required','numeric','between:-180,180'],
            'pickup.label' => ['nullable','string','max:255'],
            'dropoff.lat'  => ['required','numeric','between:-90,90'],
            'dropoff.lng'  => ['required','numeric','between:-180,180'],
            'dropoff.label'=> ['nullable','string','max:255'],
            'distance_m'   => ['required','numeric','min:1'],
            'duration_s'   => ['required','numeric','min:1'],
            'price'        => ['required','numeric','min:1'],
            'passenger_name' => ['nullable','string','max:255'],
            'passenger_phone' => ['nullable','string','max:255'],
            'vehicle_type'   => ['nullable','string','in:standard,vip'],
            'has_baggage'    => ['nullable','boolean'],
        ]);

        $pickupLat = (float) $request->input('pickup.lat');
        $pickupLng = (float) $request->input('pickup.lng');
        $pickupLabel = $request->input('pickup.label');
        $dropoffLat = (float) $request->input('dropoff.lat');
        $dropoffLng = (float) $request->input('dropoff.lng');
        $dropoffLabel = $request->input('dropoff.label');

        $ride = Ride::create([
            'rider_id' => $user?->id,
            'driver_id' => null,
            'offered_driver_id' => null,
            'status' => 'requested',
            'fare_amount' => (int) $request->input('price'),
            'commission_amount' => 0,
            'driver_earnings_amount' => 0,
            'currency' => 'XOF',
            'distance_m' => (int) $request->input('distance_m'),
            'duration_s' => (int) $request->input('duration_s'),
            'pickup_lat' => $pickupLat,
            'pickup_lng' => $pickupLng,
            'pickup_address' => $pickupLabel,
            'dropoff_lat' => $dropoffLat,
            'dropoff_lng' => $dropoffLng,
            'dropoff_address' => $dropoffLabel,
            'passenger_name' => $request->input('passenger_name'),
            'passenger_phone' => $request->input('passenger_phone'),
            'vehicle_type' => $request->input('vehicle_type', 'standard'),
            'has_baggage' => (bool)$request->input('has_baggage', false),
            'declined_driver_ids' => [],
        ]);
        // True Broadcast: on laisse offered_driver_id à null pour que tous les chauffeurs éligibles voient la course
        broadcast(new RideRequested($ride));

        return response()->json([
            'id' => $ride->id,
            'status' => $ride->status,
            'rider_id' => $ride->rider_id,
            'driver_id' => $ride->driver_id,
            'offered_driver_id' => $ride->offered_driver_id,
            'distance_m' => $ride->distance_m,
            'duration_s' => $ride->duration_s,
            'price' => $ride->fare_amount,
            'currency' => $ride->currency,
            'passenger_name' => $ride->passenger_name,
            'passenger_phone' => $ride->passenger_phone,
            'vehicle_type' => $ride->vehicle_type,
            'has_baggage' => (bool)$ride->has_baggage,
        ], 201);
    }

    public function accept(Request $request, int $id)
    {
        /** @var User|null $driver */
        $driver = Auth::user();
        $ride = Ride::findOrFail($id);
        if ($ride->status !== 'requested') {
            return response()->json(['message' => 'Ride not available'], 422);
        }
        // Dans le mode broadcast, on autorise n'importe quel chauffeur à accepter 
        // une course au statut 'requested'.
        if ($ride->offered_driver_id && $ride->offered_driver_id !== $driver->id) {
            // Optionnel : On peut quand même garder une priorité au chauffeur ciblé
            // ou permettre à n'importe qui d'accepter si c'est un broadcast.
            // Pour l'instant, on assouplit pour permettre la compétition.
        }
        // Compatibilité : si driver_id est déjà fixé à un autre chauffeur, refuser
        if ($ride->driver_id && $ride->driver_id !== ($driver?->id)) {
            return response()->json(['message' => 'Ride assigned to another driver'], 422);
        }

        $ride->driver_id = $driver?->id;
        $ride->offered_driver_id = $driver?->id;
        $ride->status = 'accepted';
        $ride->accepted_at = now();
        $ride->save();

        broadcast(new RideAccepted($ride->load('driver')));

        return response()->json(['ok' => true, 'ride_id' => $ride->id, 'status' => $ride->status]);
    }

    public function decline(Request $request, int $id)
    {
        /** @var User|null $driver */
        $driver = Auth::user();
        if (!$driver || !$driver->isDriver()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $ride = Ride::findOrFail($id);
        if ($ride->status !== 'requested') {
            return response()->json(['message' => 'Ride not available'], 422);
        }
        if ($ride->offered_driver_id !== $driver->id) {
            return response()->json(['message' => 'Ride not offered to this driver'], 422);
        }

        $declined = $ride->declined_driver_ids ?? [];
        if (!in_array($driver->id, $declined, true)) {
            $declined[] = $driver->id;
        }

        if ($ride->driver_id === $driver->id) {
            $ride->driver_id = null;
        }

        $ride->declined_driver_ids = $declined;
        $ride->offered_driver_id = null;
        $ride->save();

        broadcast(new RideDeclined($ride->fresh(['rider']), $driver));

        $nextDriver = $this->offerRideToNextDriver($ride);

        return response()->json([
            'ok' => true,
            'reoffered' => $nextDriver !== null,
            'next_driver_id' => $nextDriver?->id,
        ]);
    }

    public function start(Request $request, int $id)
    {
        /** @var User|null $driver */
        $driver = Auth::user();
        $ride = Ride::findOrFail($id);
        if ($ride->driver_id !== ($driver?->id) || $ride->status !== 'accepted') {
            return response()->json(['message' => 'Invalid state'], 422);
        }
        $ride->status = 'ongoing';
        $ride->started_at = now();
        $ride->save();
        
        broadcast(new RideStarted($ride));
        
        return response()->json(['ok' => true, 'ride_id' => $ride->id, 'status' => $ride->status]);
    }

    public function complete(Request $request, int $id)
    {
        /** @var User|null $driver */
        $driver = Auth::user();
        $ride = Ride::findOrFail($id);
        if ($ride->driver_id !== ($driver?->id) || $ride->status !== 'ongoing') {
            return response()->json(['message' => 'Invalid state'], 422);
        }

        return DB::transaction(function () use ($ride, $driver) {
            $fare = (int) $ride->fare_amount;
            $commission = (int) round($fare * 0.85); // Platform gets 85%
            $earn = $fare - $commission; // Driver gets 15% (or $fare * 0.15)

            $ride->commission_amount = $commission;
            $ride->driver_earnings_amount = $earn;
            $ride->status = 'completed';
            $ride->completed_at = now();
            $ride->save();

            // Créditer le portefeuille du chauffeur
            $walletController = new WalletController();
            $wallet = DB::table('wallets')->where('user_id', $driver->id)->first();
            
            if (!$wallet) {
                DB::table('wallets')->insert([
                    'user_id' => $driver->id,
                    'balance' => 0,
                    'currency' => $ride->currency ?? 'XOF',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $wallet = DB::table('wallets')->where('user_id', $driver->id)->first();
            }

            $before = (int) $wallet->balance;
            $after = $before + $earn;

            DB::table('wallet_transactions')->insert([
                'wallet_id' => $wallet->id,
                'type' => 'credit',
                'source' => 'ride_earnings',
                'amount' => $earn,
                'balance_before' => $before,
                'balance_after' => $after,
                'meta' => json_encode(['ride_id' => $ride->id]),
                'created_at' => now(),
            ]);

            DB::table('wallets')->where('id', $wallet->id)->update([
                'balance' => $after,
                'updated_at' => now(),
            ]);

            broadcast(new RideCompleted($ride));

            return response()->json(['ok' => true, 'ride_id' => $ride->id, 'status' => $ride->status, 'earned' => $earn]);
        });
    }

    public function cancelByDriver(Request $request, int $id)
    {
        /** @var User|null $driver */
        $driver = Auth::user();
        if (!$driver || !$driver->isDriver()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $ride = Ride::findOrFail($id);
        if (!in_array($ride->status, ['accepted','ongoing','requested'])) {
            return response()->json(['message' => 'Invalid state'], 422);
        }
        $data = $request->validate([
            'reason' => ['nullable','string','max:120'],
        ]);
        $ride->status = 'cancelled';
        $ride->cancelled_at = now();
        $ride->cancellation_reason = $data['reason'] ?? null;
        $ride->save();

        broadcast(new RideCancelled($ride->fresh(['driver','rider']), 'driver', $driver));

        return response()->json(['ok' => true, 'ride_id' => $ride->id, 'status' => $ride->status]);
    }

    public function cancelByPassenger(Request $request, int $id)
    {
        /** @var User|null $user */
        $user = Auth::user();
        $ride = Ride::findOrFail($id);
        if ($ride->rider_id !== ($user?->id) || !in_array($ride->status, ['requested','accepted'])) {
            return response()->json(['message' => 'Invalid state'], 422);
        }
        $data = $request->validate([
            'reason' => ['nullable','string','max:120'],
        ]);
        $ride->status = 'cancelled';
        $ride->cancelled_at = now();
        $ride->cancellation_reason = $data['reason'] ?? null;
        $ride->save();

        broadcast(new RideCancelled($ride, 'passenger', $user));

        return response()->json(['ok' => true, 'ride_id' => $ride->id, 'status' => $ride->status]);
    }

    public function updateDriverStatus(Request $request)
    {
        /** @var User|null $driver */
        $driver = Auth::user();
        if (!$driver || !$driver->isDriver()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'online' => ['required','boolean'],
        ]);

        $driver->is_online = (bool) $data['online'];
        $driver->save();

        return response()->json([
            'ok' => true,
            'user_id' => $driver->id,
            'is_online' => $driver->is_online,
        ]);
    }

    public function driverRides(Request $request)
    {
        /** @var User|null $driver */
        $driver = Auth::user();
        if (!$driver || !$driver->isDriver()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $status = $request->query('status');
        $perPage = (int) $request->query('per_page', 20);

        $query = Ride::query()->where('driver_id', $driver->id);
        if ($status) {
            $query->where('status', $status);
        }

        $rides = $query->orderByDesc('id')->paginate($perPage);
        return response()->json($rides);
    }

    public function driverRideShow(int $id)
    {
        /** @var User|null $driver */
        $driver = Auth::user();
        if (!$driver || !$driver->isDriver()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $ride = Ride::findOrFail($id);
        if ($ride->driver_id !== $driver->id) {
            return response()->json(['message' => 'Not your ride'], 403);
        }

        return response()->json($ride);
    }

    public function currentPassengerRide(Request $request)
    {
        /** @var User|null $user */
        $user = Auth::user();
        if (!$user || !$user->isPassenger()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $ride = Ride::where('rider_id', $user->id)
            ->whereIn('status', ['requested', 'accepted', 'arrived', 'started', 'ongoing'])
            ->with(['driver', 'vehicle'])
            ->orderByDesc('id')
            ->first();

        return response()->json($ride);
    }

    public function activeRidesCount(Request $request)
    {
        /** @var User|null $user */
        $user = Auth::user();
        if (!$user || !$user->isPassenger()) {
            return response()->json(['count' => 0]);
        }

        $count = Ride::where('rider_id', $user->id)
            ->whereIn('status', ['requested', 'accepted', 'arrived', 'started', 'ongoing'])
            ->count();

        return response()->json(['count' => $count]);
    }

    public function passengerRides(Request $request)
    {
        /** @var User|null $user */
        $user = Auth::user();
        if (!$user || !$user->isPassenger()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $status = $request->query('status');
        $perPage = (int) $request->query('per_page', 20);

        $query = Ride::query()->where('rider_id', $user->id);
        if ($status) {
            $query->where('status', $status);
        }

        $rides = $query->orderByDesc('id')->paginate($perPage);
        return response()->json($rides);
    }

    public function passengerRideShow(int $id)
    {
        /** @var User|null $user */
        $user = Auth::user();
        if (!$user || !$user->isPassenger()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        $ride = Ride::with('driver')->findOrFail($id);
        if ($ride->rider_id !== $user->id) {
            return response()->json(['message' => 'Not your ride'], 403);
        }

        return response()->json([
            'id' => $ride->id,
            'status' => $ride->status,
            'rider_id' => $ride->rider_id,
            'driver_id' => $ride->driver_id,
            'fare_amount' => (int) ($ride->fare_amount ?? 0),
            'currency' => $ride->currency ?? 'XOF',
            'distance_m' => (int) ($ride->distance_m ?? 0),
            'duration_s' => (int) ($ride->duration_s ?? 0),
            'pickup' => [
                'address' => $ride->pickup_address ?? null,
                'lat' => $ride->pickup_lat ?? null,
                'lng' => $ride->pickup_lng ?? null,
            ],
            'dropoff' => [
                'address' => $ride->dropoff_address ?? null,
                'lat' => $ride->dropoff_lat ?? null,
                'lng' => $ride->dropoff_lng ?? null,
            ],
            'driver' => $ride->driver ? [
                'id' => $ride->driver->id,
                'name' => $ride->driver->name,
                'phone' => $ride->driver->phone,
            ] : null,
            'passenger_name' => $ride->passenger_name,
            'passenger_phone' => $ride->passenger_phone,
        ]);
    }

    public function driverStats(Request $request)
    {
        /** @var User|null $driver */
        $driver = Auth::user();
        if (!$driver || !$driver->isDriver()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $from = $request->query('from');
        $to = $request->query('to');

        // Completed rides and earnings over the selected range
        $completedQuery = Ride::query()
            ->where('driver_id', $driver->id)
            ->where('status', 'completed');

        if ($from && $to) {
            $completedQuery->whereBetween('completed_at', [$from, $to]);
        }

        $totalRides = (clone $completedQuery)->count();
        $totalEarnings = (clone $completedQuery)->sum('driver_earnings_amount');

        // Acceptance / cancellation rates: consider all rides assigned to this driver in the range
        $assignedQuery = Ride::query()->where('driver_id', $driver->id);
        if ($from && $to) {
            $assignedQuery->whereBetween('created_at', [$from, $to]);
        }

        $totalAssigned = (clone $assignedQuery)->count();
        $acceptedCount = (clone $assignedQuery)
            ->whereIn('status', ['accepted', 'ongoing', 'completed'])
            ->count();
        $cancelledCount = (clone $assignedQuery)
            ->where('status', 'cancelled')
            ->count();

        $acceptanceRate = $totalAssigned > 0
            ? round(($acceptedCount * 100.0) / $totalAssigned, 1)
            : 0.0;
        $cancellationRate = $totalAssigned > 0
            ? round(($cancelledCount * 100.0) / $totalAssigned, 1)
            : 0.0;

        // Rating: lifetime average and count of ratings for this driver
        $ratingRow = DB::table('ratings')
            ->where('driver_id', $driver->id)
            ->selectRaw('COALESCE(AVG(stars),0) as avg_stars, COUNT(*) as cnt')
            ->first();

        $ratingAverage = $ratingRow && $ratingRow->cnt > 0
            ? round((float) $ratingRow->avg_stars, 2)
            : null;
        $ratingCount = $ratingRow ? (int) $ratingRow->cnt : 0;

        return response()->json([
            'driver_id' => $driver->id,
            'total_rides' => $totalRides,
            'total_earnings' => (int) $totalEarnings,
            'currency' => 'XOF',
            'range' => [
                'from' => $from,
                'to' => $to,
            ],
            'rating_average' => $ratingAverage,
            'rating_count' => $ratingCount,
            'acceptance_rate' => $acceptanceRate,
            'cancellation_rate' => $cancellationRate,
            'online_hours' => null,
        ]);
    }

    public function driverCurrentRide()
    {
        /** @var User|null $driver */
        $driver = Auth::user();
        if (!$driver || !$driver->isDriver()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $lat = $driver->last_lat;
        $lng = $driver->last_lng;
        
        $earthRadiusKm = 6371.0;
        $searchRadiusKm = (float) config('app.search_radius_km', 10.0);
        
        $distanceFormula = "(
            {$earthRadiusKm} * 2 * ASIN(
                SQRT(
                    POWER(SIN(RADIANS({$lat} - rides.pickup_lat) / 2), 2) +
                    COS(RADIANS({$lat})) * COS(RADIANS(rides.pickup_lat)) *
                    POWER(SIN(RADIANS({$lng} - rides.pickup_lng) / 2), 2)
                )
            )
        )";

        $ride = Ride::query()
            ->where(function ($query) use ($driver, $distanceFormula, $searchRadiusKm) {
                $query->where('driver_id', $driver->id)
                    ->whereIn('status', ['accepted', 'ongoing']);
            })
            ->orWhere(function ($query) use ($driver, $distanceFormula, $searchRadiusKm) {
                $query->where('offered_driver_id', $driver->id)
                    ->where('status', 'requested');
            })
            ->orWhere(function ($query) use ($driver, $distanceFormula, $searchRadiusKm) {
                if (!$driver->is_online) {
                    $query->whereRaw('0 = 1'); // Force empty result for this branch if offline
                    return;
                }
                $query->where('status', 'requested')
                    ->whereNull('offered_driver_id')
                    ->whereNotNull('pickup_lat')
                    ->whereNotNull('pickup_lng')
                    ->whereRaw("{$distanceFormula} <= ?", [$searchRadiusKm])
                    ->where(function($q) use ($driver) {
                        $q->whereNull('declined_driver_ids')
                          ->orWhereRaw("NOT JSON_CONTAINS(declined_driver_ids, CAST(? AS JSON))", [json_encode($driver->id)]);
                    });
            })
            ->orderByDesc('id')
            ->first();

        if (!$ride) {
            return response()->json(null, 204);
        }

        $passenger = $ride->rider_id ? User::find($ride->rider_id) : null;

        return response()->json([
            'id' => $ride->id,
            'pickup_address' => $ride->pickup_address,
            'dropoff_address' => $ride->dropoff_address,
            'fare_amount' => (int) ($ride->fare_amount ?? 0),
            'status' => $ride->status,
            'pickup_lat' => $ride->pickup_lat,
            'pickup_lng' => $ride->pickup_lng,
            'dropoff_lat' => $ride->dropoff_lat,
            'dropoff_lng' => $ride->dropoff_lng,
            'accepted_at' => $ride->accepted_at,
            'started_at' => $ride->started_at,
            'completed_at' => $ride->completed_at,
            'rider' => $this->formatPassenger($passenger),
            'passenger_name' => $ride->passenger_name,
            'passenger_phone' => $ride->passenger_phone,
        ]);
    }

    public function driverNextOffer(Request $request)
    {
        /** @var User|null $driver */
        $driver = Auth::user();
        if (!$driver || !$driver->isDriver()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // Nouveau système : Diffusion au lieu de ciblage individuel
        // On cherche une course 'requested' dans un rayon de 10km du chauffeur
        $lat = $driver->last_lat;
        $lng = $driver->last_lng;
        
        if ($lat === null || $lng === null) {
            return response()->json(null, 204);
        }

        $earthRadiusKm = 6371.0;
        $searchRadiusKm = (float) config('app.search_radius_km', 10.0);
        
        $distanceFormula = "(
            {$earthRadiusKm} * 2 * ASIN(
                SQRT(
                    POWER(SIN(RADIANS({$lat} - rides.pickup_lat) / 2), 2) +
                    COS(RADIANS({$lat})) * COS(RADIANS(rides.pickup_lat)) *
                    POWER(SIN(RADIANS({$lng} - rides.pickup_lng) / 2), 2)
                )
            )
        )";

        $ride = Ride::query()
            ->where('status', 'requested')
            ->whereNotNull('pickup_lat')
            ->whereNotNull('pickup_lng')
            ->whereRaw("{$distanceFormula} <= ?", [$searchRadiusKm]);

        // Exclure les courses déjà déclinées par ce chauffeur
        $ride->where(function($q) use ($driver) {
            $q->whereNull('declined_driver_ids')
              ->orWhereRaw("NOT JSON_CONTAINS(declined_driver_ids, CAST(? AS JSON))", [json_encode($driver->id)]);
        });

        $ride = $ride->orderByDesc('id')->first();

        if (!$ride) {
            return response()->json(null, 204);
        }

        $passenger = $ride->rider_id ? User::find($ride->rider_id) : null;

        return response()->json([
            'id' => $ride->id,
            'pickup_address' => $ride->pickup_address ?? null,
            'dropoff_address' => $ride->dropoff_address ?? null,
            'fare_amount' => (int) ($ride->fare_amount ?? 0),
            'status' => $ride->status,
            'pickup_lat' => $ride->pickup_lat,
            'pickup_lng' => $ride->pickup_lng,
            'dropoff_lat' => $ride->dropoff_lat,
            'dropoff_lng' => $ride->dropoff_lng,
            'rider' => $this->formatPassenger($passenger),
        ]);
    }

    public function updateDriverLocation(Request $request)
    {
        /** @var User|null $driver */
        $driver = Auth::user();
        if (!$driver || !$driver->isDriver()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        

        $data = $request->validate([
            'lat' => ['required','numeric','between:-90,90'],
            'lng' => ['required','numeric','between:-180,180'],
        ]);

        $driver->last_lat = (float) $data['lat'];
        $driver->last_lng = (float) $data['lng'];
        $driver->last_location_at = now();
        $driver->save();

        $rideId = $request->input('ride_id');
        $ride = null;
        if ($rideId) {
            $ride = Ride::find($rideId);
        }
        if (!$ride) {
            $ride = Ride::query()
                ->where('driver_id', $driver->id)
                ->whereIn('status', ['accepted', 'ongoing'])
                ->orderByDesc('id')
                ->first();
        }

        if ($ride) {
            broadcast(new DriverLocationUpdated(
                $ride->id,
                [
                    'lat' => (float) $driver->last_lat,
                    'lng' => (float) $driver->last_lng,
                    'updated_at' => optional($driver->last_location_at)?->toIso8601String(),
                ]
            ));
        }

        return response()->json([
            'ok' => true,
            'user_id' => $driver->id,
            'lat' => $driver->last_lat,
            'lng' => $driver->last_lng,
            'updated_at' => $driver->last_location_at,
        ]);
    }

    public function passengerRideDriverLocation(int $id)
    {
        /** @var User|null $user */
        $user = Auth::user();
        if (!$user || !$user->isPassenger()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $ride = Ride::findOrFail($id);
        if ($ride->rider_id !== $user->id) {
            return response()->json(['message' => 'Not your ride'], 403);
        }
        if (!$ride->driver_id) {
            return response()->json(['message' => 'No driver assigned'], 422);
        }

        $driver = User::find($ride->driver_id);
        if (!$driver) {
            return response()->json(['message' => 'Chauffeur non trouvé'], 404);
        }

        return response()->json([
            'driver_id' => $driver->id,
            'lat' => $driver->last_lat,
            'lng' => $driver->last_lng,
            'updated_at' => $driver->last_location_at,
        ]);
    }

    public function passengerRideWaitAssignment(Request $request, int $id)
    {
        /** @var User|null $user */
        $user = Auth::user();
        if (!$user || !$user->isPassenger()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $ride = Ride::with('driver')->findOrFail($id);
        if ($ride->rider_id !== $user->id) {
            return response()->json(['message' => 'Not your ride'], 403);
        }

        $timeoutSeconds = (int) $request->query('timeout', 25);
        $timeoutSeconds = max(5, min($timeoutSeconds, 60));
        $sleepMicroseconds = 500000; // 0.5s
        $iterations = (int) ceil(($timeoutSeconds * 1000000) / $sleepMicroseconds);

        for ($i = 0; $i < $iterations; $i++) {
            $ride->refresh();
            if ($ride->driver_id) {
                $driver = $ride->driver_id ? User::find($ride->driver_id) : null;
                return response()->json([
                    'id' => $ride->id,
                    'status' => $ride->status,
                    'driver' => $this->formatPassenger($driver),
                    'passenger_name' => $ride->passenger_name,
                    'passenger_phone' => $ride->passenger_phone,
                ]);
            }
            usleep($sleepMicroseconds);
        }

        return response()->json(null, 204);
    }

    protected function offerRideToNextDriver(Ride $ride, array $extraExclude = []): ?User
    {
        if ($ride->pickup_lat === null || $ride->pickup_lng === null) {
            return null;
        }

        $excludeIds = $this->buildDriverExcludeList($ride, $extraExclude);
        $candidate = $this->findDriverCandidate($ride->pickup_lat, $ride->pickup_lng, $excludeIds);

        // Suppression du fallback global : seuls les chauffeurs dans les 10km sont éligibles.
        if (!$candidate) {
            return null;
        }

        $ride->offered_driver_id = $candidate?->id;
        $ride->save();

        return $candidate;
    }

    protected function buildDriverExcludeList(Ride $ride, array $extraExclude = []): array
    {
        $declined = $ride->declined_driver_ids ?? [];
        $ids = array_merge(
            $extraExclude,
            is_array($declined) ? $declined : [],
            $ride->driver_id ? [$ride->driver_id] : []
        );

        $ids = array_filter(array_map(fn ($id) => $id ? (int) $id : null, $ids));

        return array_values(array_unique($ids));
    }

    protected function findDriverCandidate(float $pickupLat, float $pickupLng, array $excludeIds = []): ?User
    {
        $earthRadiusKm = 6371.0;
        $searchRadiusKm = (float) config('app.search_radius_km', 10.0);

        // Calcul de la distance à l'aide de la formule Haversine
        $distanceFormula = "(
            {$earthRadiusKm} * 2 * ASIN(
                SQRT(
                    POWER(SIN(RADIANS({$pickupLat} - users.last_lat) / 2), 2) +
                    COS(RADIANS({$pickupLat})) * COS(RADIANS(users.last_lat)) *
                    POWER(SIN(RADIANS({$pickupLng} - users.last_lng) / 2), 2)
                )
            )
        )";

        $query = User::query()
            ->where('role', 'driver')
            ->where('is_active', true)
            ->where('is_online', true)
            ->join('driver_profiles', 'driver_profiles.user_id', '=', 'users.id')
            ->where('driver_profiles.status', 'approved')
            ->whereNotNull('users.last_lat')
            ->whereNotNull('users.last_lng')
            ->whereRaw("{$distanceFormula} <= ?", [$searchRadiusKm])
            ->select('users.*')
            ->selectRaw("{$distanceFormula} as distance_km")
            ->orderByRaw("{$distanceFormula} ASC")
            ->orderByDesc('users.last_location_at')
            ->orderBy('users.id');

        if (!empty($excludeIds)) {
            $query->whereNotIn('users.id', $excludeIds);
        }

        return $query->first();
    }

    protected function findDriverFallback(array $excludeIds = []): ?User
    {
        $query = User::query()
            ->where('role', 'driver')
            ->where('is_active', true)
            ->where('is_online', true)
            ->join('driver_profiles', 'driver_profiles.user_id', '=', 'users.id')
            ->where('driver_profiles.status', 'approved')
            ->select('users.*')
            ->orderByDesc('users.last_location_at')
            ->orderBy('users.id');

        if (!empty($excludeIds)) {
            $query->whereNotIn('users.id', $excludeIds);
        }

        return $query->first();
    }

    protected function formatPassenger(?User $user): ?array
    {
        if (!$user) {
            return null;
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'phone' => $user->phone,
            'photo' => $user->photo,
        ];
    }

    /**
     * Update driver's vehicle information
     */
    public function updateVehicle(Request $request)
    {
        /** @var User|null $driver */
        $driver = Auth::user();
        if (!$driver || !$driver->isDriver()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'vehicle_make' => ['required', 'string', 'max:100'],
            'vehicle_model' => ['required', 'string', 'max:100'],
            'vehicle_year' => ['nullable', 'string', 'max:4'],
            'vehicle_color' => ['nullable', 'string', 'max:50'],
            'license_plate' => ['required', 'string', 'max:20'],
            'vehicle_type' => ['nullable', 'string', 'in:sedan,suv,van,compact'],
        ]);

        // Get or create driver profile
        $profile = $driver->driverProfile;
        if (!$profile) {
            return response()->json(['message' => 'Profil chauffeur non trouvé'], 404);
        }

        // Update vehicle information
        $profile->vehicle_make = $data['vehicle_make'];
        $profile->vehicle_model = $data['vehicle_model'];
        $profile->vehicle_year = $data['vehicle_year'] ?? null;
        $profile->vehicle_color = $data['vehicle_color'] ?? null;
        $profile->license_plate = $data['license_plate'];
        $profile->vehicle_type = $data['vehicle_type'] ?? 'sedan';
        $profile->save();

        return response()->json([
            'success' => true,
            'message' => 'Informations du véhicule mises à jour avec succès',
            'vehicle' => [
                'make' => $profile->vehicle_make,
                'model' => $profile->vehicle_model,
                'year' => $profile->vehicle_year,
                'color' => $profile->vehicle_color,
                'license_plate' => $profile->license_plate,
                'type' => $profile->vehicle_type,
            ],
        ]);
    }
}
