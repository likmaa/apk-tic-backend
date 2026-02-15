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
use App\Events\RideStopUpdated;
use App\Events\RideArrived;
use App\Models\FcmToken;
use App\Services\FcmService;
use App\Services\RideService;

use App\Models\PricingSetting;

use App\Models\Ride;
use App\Models\User;

class TripsController extends Controller
{
    protected $rideService;

    public function __construct(RideService $rideService)
    {
        $this->rideService = $rideService;
    }

    public function estimate(Request $request)
    {
        $data = $request->validate([
            'pickup.lat' => ['required', 'numeric', 'between:-90,90'],
            'pickup.lng' => ['required', 'numeric', 'between:-180,180'],
            'dropoff.lat' => ['required', 'numeric', 'between:-90,90'],
            'dropoff.lng' => ['required', 'numeric', 'between:-180,180'],
            'distance_m' => ['required', 'numeric', 'min:1'],
            'duration_s' => ['required', 'numeric', 'min:1'],
            'vehicle_type' => ['nullable', 'string', 'in:standard,vip'],
            'luggage_count' => ['nullable', 'integer', 'min:0', 'max:3'],
        ]);

        $vehicleType = $request->input('vehicle_type', 'standard');
        $luggageCount = (int) $request->input('luggage_count', 0);

        $distance = (float) $request->input('distance_m');
        $duration = (float) $request->input('duration_s');

        $km = (double) ($distance / 1000.0);
        $pricing = $this->getPricingConfig();

        $base = (float) $pricing['base_fare'];
        $perKm = (float) $pricing['per_km'];

        // Formula: Base + (Distance_km * Rate/km)
        $price = $base + ($perKm * $km);

        // VIP Multiplier
        if ($vehicleType === 'vip') {
            $price *= 1.5;
        }

        // Peak Hours Multiplier
        $peak = $pricing['peak_hours'];
        if ($peak['enabled'] && $this->isCurrentlyInTimeRange($peak['start_time'], $peak['end_time'])) {
            $price *= (float) $peak['multiplier'];
        }

        // Weather Multiplier
        $weather = $pricing['weather'];
        if ($weather['enabled']) {
            $price *= (float) $weather['multiplier'];
        }

        // Night Multiplier
        $night = $pricing['night'];
        if ($night['multiplier'] > 1.0 && $this->isCurrentlyInTimeRange($night['start_time'], $night['end_time'])) {
            $price *= (float) $night['multiplier'];
        }

        $minFare = (float) $pricing['min_fare'];
        $price = max($minFare, (int) round($price));

        // Add Luggage Fee (Centralized price per unit)
        $luggagePrice = $luggageCount * ($pricing['luggage_unit_price'] ?? 500);
        $price += $luggagePrice;

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
            'pickup.lat' => ['required', 'numeric', 'between:-90,90'],
            'pickup.lng' => ['required', 'numeric', 'between:-180,180'],
            'dropoff.lat' => ['required', 'numeric', 'between:-90,90'],
            'dropoff.lng' => ['required', 'numeric', 'between:-180,180'],
            'vehicle_type' => ['nullable', 'string', 'in:standard,vip'],
            'luggage_count' => ['nullable', 'integer', 'min:0', 'max:3'],
        ]);

        $vehicleType = $request->input('vehicle_type', 'standard');
        $luggageCount = (int) $request->input('luggage_count', 0);

        $pickLat = (float) $request->input('pickup.lat');
        $pickLng = (float) $request->input('pickup.lng');
        $dropLat = (float) $request->input('dropoff.lat');
        $dropLng = (float) $request->input('dropoff.lng');

        // Utilise OSRM public pour récupérer distance et durée + géométrie
        $url = 'https://router.project-osrm.org/route/v1/driving/' . $pickLng . ',' . $pickLat . ';' . $dropLng . ',' . $dropLat . '?overview=full&geometries=geojson';

        try {
            $resp = Http::timeout(4)->get($url); // Reduced from 8s to 4s
            if (!$resp->ok()) {
                \Log::error("OSRM Routing failed for ride estimate", [
                    'status' => $resp->status(),
                    'body' => $resp->body(),
                    'url' => $url
                ]);
                return response()->json(['message' => 'Routing services temporarily unavailable', 'status' => $resp->status()], 502);
            }
        } catch (\Exception $e) {
            \Log::error("OSRM Routing timeout or exception", [
                'error' => $e->getMessage(),
                'url' => $url
            ]);
            return response()->json(['message' => 'Routing service timeout'], 504);
        }

        $json = $resp->json();
        $route = $json['routes'][0] ?? null;
        if (!$route) {
            return response()->json(['message' => 'No route'], 422);
        }
        $distance = (float) ($route['distance'] ?? 0);   // en mètres
        $duration = (float) ($route['duration'] ?? 0);   // en secondes
        $geometry = $route['geometry'] ?? null;

        $km = (double) ($distance / 1000.0);
        $pricing = $this->getPricingConfig();

        $base = (float) $pricing['base_fare'];
        $perKm = (float) $pricing['per_km'];
        $price = $base + ($perKm * $km);

        // VIP Multiplier
        if ($vehicleType === 'vip') {
            $price *= 1.5;
        }

        // Peak Hours Multiplier
        $peak = $pricing['peak_hours'];
        if ($peak['enabled'] && $this->isCurrentlyInTimeRange($peak['start_time'], $peak['end_time'])) {
            $price *= (float) $peak['multiplier'];
        }

        // Weather Multiplier
        $weather = $pricing['weather'];
        if ($weather['enabled']) {
            $price *= (float) $weather['multiplier'];
        }

        // Night Multiplier
        $night = $pricing['night'];
        if ($night['multiplier'] > 1.0 && $this->isCurrentlyInTimeRange($night['start_time'], $night['end_time'])) {
            $price *= (float) $night['multiplier'];
        }

        $minFare = (float) $pricing['min_fare'];
        $price = max($minFare, (int) round($price));

        // Add Luggage Fee
        $luggagePrice = $luggageCount * ($pricing['luggage_unit_price'] ?? 500);
        $totalPrice = (int) ($price + $luggagePrice);

        return response()->json([
            'price' => $totalPrice,
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
            'pickup_label' => ['required', 'string', 'max:255'],
            'dropoff_label' => ['required', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:1'],
            'pickup_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'pickup_lng' => ['nullable', 'numeric', 'between:-180,180'],
            'dropoff_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'dropoff_lng' => ['nullable', 'numeric', 'between:-180,180'],
            'passenger_name' => ['nullable', 'string', 'max:255'],
            'passenger_phone' => ['nullable', 'string', 'max:255'],
            'vehicle_type' => ['nullable', 'string', 'in:standard,vip'],
            'has_baggage' => ['nullable', 'boolean'],
            'payment_method' => ['nullable', 'string', 'in:cash,wallet,card,qr'],
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
            'payment_method' => $data['payment_method'] ?? 'cash',
            'declined_driver_ids' => [],
        ]);

        // Si on a des coordonnées pickup, on tente une assignation immédiate
        if ($ride->pickup_lat !== null && $ride->pickup_lng !== null) {
            $this->rideService->notifyNearbyDrivers($ride);
        }

        return response()->json([
            'id' => $ride->id,
            'status' => $ride->status,
            'price' => $ride->fare_amount,
            'currency' => $ride->currency,
            'passenger_name' => $ride->passenger_name,
            'passenger_phone' => $ride->passenger_phone,
            'stop_started_at' => $ride->stop_started_at,
            'total_stop_duration_s' => $ride->total_stop_duration_s,
            ...$this->calculateRideFareBreakdown($ride),
            'vehicle_type' => $ride->vehicle_type,
            'has_baggage' => (bool) $ride->has_baggage,
        ], 201);
    }

    public function create(Request $request)
    {
        /** @var User|null $user */
        $user = Auth::user();
        $data = $request->validate([
            'pickup.lat' => ['required', 'numeric', 'between:-90,90'],
            'pickup.lng' => ['required', 'numeric', 'between:-180,180'],
            'pickup.label' => ['nullable', 'string', 'max:255'],
            'dropoff.lat' => ['required', 'numeric', 'between:-90,90'],
            'dropoff.lng' => ['required', 'numeric', 'between:-180,180'],
            'dropoff.label' => ['nullable', 'string', 'max:255'],
            'distance_m' => ['required', 'numeric', 'min:1'],
            'duration_s' => ['required', 'numeric', 'min:1'],
            'price' => ['required', 'numeric', 'min:1'],
            'passenger_name' => ['nullable', 'string', 'max:255'],
            'passenger_phone' => ['nullable', 'string', 'max:255'],
            'vehicle_type' => ['nullable', 'string', 'in:standard,vip'],
            'has_baggage' => ['nullable', 'boolean'],
            'luggage_count' => ['nullable', 'integer', 'min:0', 'max:5'],
            'payment_method' => ['nullable', 'string', 'in:cash,wallet,card,qr'],
            'service_type' => ['nullable', 'string', 'in:course,livraison,deplacement'],
            'recipient_name' => ['nullable', 'string', 'max:255'],
            'recipient_phone' => ['nullable', 'string', 'max:255'],
            'package_description' => ['nullable', 'string'],
            'package_weight' => ['nullable', 'string'],
            'is_fragile' => ['nullable', 'boolean'],
        ]);

        // Anti-duplicate: check for existing active ride
        if ($user) {
            $existingRide = Ride::where('rider_id', $user->id)
                ->whereIn('status', ['requested', 'accepted', 'arrived', 'pickup'])
                ->where('created_at', '>', now()->subMinutes(30))
                ->first();

            if ($existingRide) {
                return response()->json([
                    'message' => 'La course que vous venez de lancé est déjà en cours. Veuillez patienter.',
                    'id' => $existingRide->id,
                    'status' => $existingRide->status
                ], 422);
            }
        }

        try {


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
                'has_baggage' => (bool) $request->input('has_baggage', false),
                'luggage_count' => (int) $request->input('luggage_count', 0),
                'payment_method' => $request->input('payment_method', 'cash'),
                'service_type' => $request->input('service_type', 'course'),
                'recipient_name' => $request->input('recipient_name'),
                'recipient_phone' => $request->input('recipient_phone'),
                'package_description' => $request->input('package_description'),
                'package_weight' => $request->input('package_weight'),
                'is_fragile' => (bool) $request->input('is_fragile', false),
                'declined_driver_ids' => [],
            ]);
            $this->rideService->notifyNearbyDrivers($ride);

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
                'stop_started_at' => $ride->stop_started_at,
                'total_stop_duration_s' => $ride->total_stop_duration_s,
                ...$this->calculateRideFareBreakdown($ride),
                'vehicle_type' => $ride->vehicle_type,
                'has_baggage' => (bool) $ride->has_baggage,
            ], 201);
        } catch (\Exception $e) {
            \Log::error("CRITICAL ERROR during ride creation: " . $e->getMessage(), [
                'user_id' => $user?->id,
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'message' => 'Erreur Serveur lors de la création de la course.',
                'error' => $e->getMessage()
            ], 500);
        }
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

        // Notify passenger
        try {
            $passenger = $ride->rider;
            if ($passenger) {
                $fcm = app(FcmService::class);
                $fcm->sendToUser(
                    $passenger,
                    "Course acceptée !",
                    "Votre chauffeur " . $driver->name . " est en route.",
                    ['ride_id' => (string) $ride->id, 'type' => 'ride_accepted']
                );
            }
        } catch (\Exception $e) {
            \Log::error("FCM Ride Accepted Notification Error: " . $e->getMessage());
        }

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

    public function arrived(Request $request, int $id)
    {
        /** @var User|null $driver */
        $driver = Auth::user();
        $ride = Ride::findOrFail($id);

        if ($ride->driver_id !== ($driver?->id) || !in_array($ride->status, ['accepted', 'pickup'])) {
            return response()->json(['message' => 'Invalid state'], 422);
        }

        $ride->status = 'arrived';
        $ride->arrived_at = now();
        $ride->save();

        broadcast(new RideArrived($ride));

        // Notify passenger
        try {
            $passenger = $ride->rider;
            if ($passenger) {
                $fcm = app(FcmService::class);
                $fcm->sendToUser(
                    $passenger,
                    "Votre chauffeur est arrivé !",
                    "Le chauffeur est au point de prise en charge.",
                    ['ride_id' => (string) $ride->id, 'type' => 'driver_arrived']
                );
            }
        } catch (\Exception $e) {
            \Log::error("FCM Passenger Notification Error: " . $e->getMessage());
        }

        return response()->json(['ok' => true, 'arrived_at' => $ride->arrived_at]);
    }

    public function start(Request $request, int $id)
    {
        /** @var User|null $driver */
        $driver = Auth::user();
        $ride = Ride::findOrFail($id);
        if ($ride->driver_id !== ($driver?->id) || !in_array($ride->status, ['accepted', 'arrived', 'pickup'])) {
            return response()->json(['message' => 'Invalid state'], 422);
        }
        $ride->status = 'ongoing';
        $ride->started_at = now();
        $ride->save();

        broadcast(new RideStarted($ride));

        // Notify passenger
        try {
            $passenger = $ride->rider;
            if ($passenger) {
                $fcm = app(FcmService::class);
                $fcm->sendToUser(
                    $passenger,
                    "C'est parti !",
                    "Votre course a commencé. Bon voyage !",
                    ['ride_id' => (string) $ride->id, 'type' => 'ride_started']
                );
            }
        } catch (\Exception $e) {
            \Log::error("FCM Ride Started Notification Error: " . $e->getMessage());
        }

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

        try {
            // Execute all DB operations in a transaction
            $result = DB::transaction(function () use ($ride, $driver) {
                // Handle active stop if not ended
                if ($ride->stop_started_at) {
                    $duration = (int) now()->diffInSeconds($ride->stop_started_at, true);
                    $ride->total_stop_duration_s += $duration;
                    $ride->stop_started_at = null;
                }

                // Get pricing settings
                $pricing = Cache::remember('pricing.config', 60, function () {
                    $s = PricingSetting::query()->first();
                    return [
                        'base_fare' => (int) ($s?->base_fare ?? 500),
                        'per_km' => (int) ($s?->per_km ?? 250),
                        'min_fare' => (int) ($s?->min_fare ?? 1000),
                        'platform_pct' => (int) ($s?->platform_commission_pct ?? 70),
                        'driver_pct' => (int) ($s?->driver_commission_pct ?? 20),
                        'maintenance_pct' => (int) ($s?->maintenance_commission_pct ?? 10),
                        'luggage_unit_price' => (int) ($s?->luggage_unit_price ?? 500),
                        'stop_rate_per_min' => (int) ($s?->stop_rate_per_min ?? 5),
                        'weather' => [
                            'enabled' => (bool) ($s?->weather_mode_enabled ?? false),
                            'multiplier' => (float) ($s?->weather_multiplier ?? 1.0),
                        ],
                        'night' => [
                            'multiplier' => (float) ($s?->night_multiplier ?? 1.0),
                            'start_time' => substr((string) ($s?->night_start_time ?? '22:00'), 0, 5),
                            'end_time' => substr((string) ($s?->night_end_time ?? '06:00'), 0, 5),
                        ],
                        'peak_hours' => [
                            'enabled' => (bool) ($s?->peak_hours_enabled ?? false),
                            'multiplier' => (float) ($s?->peak_hours_multiplier ?? 1.0),
                            'start_time' => substr((string) ($s?->peak_hours_start_time ?? '17:00'), 0, 5),
                            'end_time' => substr((string) ($s?->peak_hours_end_time ?? '20:00'), 0, 5),
                        ],
                    ];
                });

                // 1. Calculate trajectory price (Base + Distance)
                $distanceKm = ($ride->distance_m ?? 0) / 1000.0;
                $trajectoryPrice = $pricing['base_fare'] + ($distanceKm * $pricing['per_km']);

                // Apply multipliers to trajectory only
                if ($ride->vehicle_type === 'vip') {
                    $trajectoryPrice *= 1.5;
                }

                if ($pricing['peak_hours']['enabled'] && $this->isCurrentlyInTimeRange($pricing['peak_hours']['start_time'], $pricing['peak_hours']['end_time'])) {
                    $trajectoryPrice *= $pricing['peak_hours']['multiplier'];
                }

                if ($pricing['weather']['enabled']) {
                    $trajectoryPrice *= $pricing['weather']['multiplier'];
                }

                if ($pricing['night']['multiplier'] > 1.0 && $this->isCurrentlyInTimeRange($pricing['night']['start_time'], $pricing['night']['end_time'])) {
                    $trajectoryPrice *= $pricing['night']['multiplier'];
                }

                // Ensure trajectory meets minimum fare
                $trajectoryPrice = max($pricing['min_fare'], (int) round($trajectoryPrice));

                // 2. Calculate stop price
                $stopMinutes = floor($ride->total_stop_duration_s / 60.0);
                $stopPrice = (int) ($stopMinutes * $pricing['stop_rate_per_min']);

                // Final fare
                $luggageCount = (int) ($ride->luggage_count ?? ($ride->has_baggage ? 1 : 0));
                $luggageFee = $luggageCount * ($pricing['luggage_unit_price'] ?? 500);
                $fare = $trajectoryPrice + $stopPrice + $luggageFee;
                $ride->fare_amount = $fare;
                $ride->breakdown = [
                    'base_fare' => $pricing['base_fare'],
                    'trajectory_fare' => $trajectoryPrice,
                    'stop_fare' => $stopPrice,
                    'luggage_fare' => $luggageFee,
                    'total_fare' => $fare,
                ];

                // 3. Calculate Commissions
                $platformPct = $pricing['platform_pct'];
                $driverPct = $pricing['driver_pct'];
                $maintenancePct = $pricing['maintenance_pct'];

                $platformAmount = (int) round($fare * ($platformPct / 100));
                $driverAmount = (int) round($fare * ($driverPct / 100));
                $maintenanceAmount = (int) round($fare * ($maintenancePct / 100));

                $totalComm = $platformAmount + $driverAmount + $maintenanceAmount;
                if ($totalComm !== $fare) {
                    $platformAmount += ($fare - $totalComm);
                }

                $ride->commission_amount = $platformAmount + $maintenanceAmount;
                $ride->driver_earnings_amount = $driverAmount;
                $ride->status = 'completed';
                $ride->completed_at = now();
                $ride->save();

                // 4. Handle Wallet Movements based on Payment Method
                $wallet = DB::table('wallets')->where('user_id', $driver->id)->first();
                if (!$wallet) {
                    $walletId = DB::table('wallets')->insertGetId([
                        'user_id' => $driver->id,
                        'balance' => 0,
                        'currency' => $ride->currency ?? 'XOF',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $wallet = (object) ['id' => $walletId, 'balance' => 0];
                }

                $before = (int) $wallet->balance;
                $after = $before;

                $pm = $ride->payment_method ?? 'cash'; // Default to cash if not set

                if ($pm === 'cash') {
                    // Cash: Driver collected 100% Fare.
                    // We must DEBIT the Commission (Platform + Maintenance).
                    $ride->payment_status = 'completed';
                    $commissionToDeduct = $ride->commission_amount; // Platform + Maint
                    $after = $before - $commissionToDeduct;

                    DB::table('wallet_transactions')->insert([
                        'wallet_id' => $wallet->id,
                        'type' => 'debit',
                        'source' => 'commission_deduction',
                        'amount' => $commissionToDeduct,
                        'balance_before' => $before,
                        'balance_after' => $after,
                        'meta' => json_encode(['ride_id' => $ride->id, 'desc' => 'Commission for cash ride']),
                        'created_at' => now(),
                    ]);
                } elseif ($pm === 'wallet') {
                    // Wallet: System collects money. 
                    // We wait for Passenger to confirm payment in their app via /passenger/rides/{id}/pay
                    // No immediate balance change for driver.
                } elseif ($pm === 'qr' || $pm === 'card') {
                    // Moneroo Payment Flow
                    // We do NOT credit driver yet. We wait for Webhook.
                    try {
                        $moneroo = new \App\Services\MonerooService();
                        // Rider info
                        $rider = User::find($ride->rider_id);
                        $customer = [
                            'email' => $rider->email ?? 'customer@example.com',
                            'first_name' => $rider->name ?? 'Client',
                            'last_name' => 'TIC',
                        ];

                        // Note: We use the ride ID + timestamp to ensure uniqueness just in case
                        $init = $moneroo->initializePayment(
                            (float) $fare,
                            'XOF',
                            'RIDE-' . $ride->id . '-' . time(),
                            "Paiement Course #{$ride->id}",
                            $customer
                        );

                        if ($init && isset($init['checkout_url'])) {
                            $ride->payment_link = $init['checkout_url'];
                            $ride->external_reference = $init['id'] ?? null;
                            $ride->payment_status = 'pending';
                            $ride->save();
                        }
                    } catch (\Exception $e) {
                        \Illuminate\Support\Facades\Log::error("Moneroo Init Failed: " . $e->getMessage());
                    }
                }

                // Only update wallet balance if changed
                if ($before !== $after) {
                    DB::table('wallets')->where('id', $wallet->id)->update([
                        'balance' => $after,
                        'updated_at' => now(),
                    ]);
                }

                // Refresh ride to get latest data and return result for broadcast
                $ride->refresh();

                return [
                    'ride' => $ride,
                    'driverAmount' => $driverAmount,
                ];
            });

            // IMPORTANT: Broadcast AFTER transaction is committed successfully
            // This ensures the passenger only receives the event when DB is actually updated
            broadcast(new RideCompleted($result['ride']));

            // Notify passenger via FCM
            try {
                $passenger = $result['ride']->rider;
                if ($passenger) {
                    $fcm = app(FcmService::class);
                    $fcm->sendToUser(
                        $passenger,
                        "Course terminée !",
                        "Merci d'avoir voyagé avec TIC. Tarif: " . number_format($result['ride']->fare_amount, 0, ',', ' ') . " FCFA",
                        ['ride_id' => (string) $result['ride']->id, 'type' => 'ride_completed']
                    );
                }
            } catch (\Exception $e) {
                \Log::error("FCM Ride Completed Notification Error: " . $e->getMessage());
            }

            \Illuminate\Support\Facades\Log::info("RideCompleted broadcast sent", [
                'ride_id' => $result['ride']->id,
                'rider_id' => $result['ride']->rider_id,
                'status' => $result['ride']->status,
            ]);

            return response()->json([
                'ok' => true,
                'ride_id' => $result['ride']->id,
                'status' => $result['ride']->status,
                'earned' => $result['driverAmount'],
                'payment_link' => $result['ride']->payment_link ?? null
            ]);

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("RideComplete FAILED", [
                'ride_id' => $id,
                'driver_id' => $driver?->id,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'message' => 'Failed to complete ride',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function cancelByDriver(Request $request, int $id)
    {
        /** @var User|null $driver */
        $driver = Auth::user();
        if (!$driver || !$driver->isDriver()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $ride = Ride::findOrFail($id);
        if (!in_array($ride->status, ['accepted', 'ongoing', 'requested'])) {
            return response()->json(['message' => 'Invalid state'], 422);
        }

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:120'],
        ]);
        $ride->status = 'cancelled';
        $ride->cancelled_at = now();
        $ride->cancellation_reason = $data['reason'] ?? null;
        $ride->save();

        broadcast(new RideCancelled($ride->fresh(['driver', 'rider']), 'driver', $driver));

        // Notify passenger
        try {
            $passenger = $ride->rider;
            if ($passenger) {
                $fcm = app(FcmService::class);
                $fcm->sendToUser(
                    $passenger,
                    "Course annulée",
                    "Le chauffeur a annulé la course.",
                    ['ride_id' => (string) $ride->id, 'type' => 'ride_cancelled']
                );
            }
        } catch (\Exception $e) {
            \Log::error("FCM Ride Cancelled By Driver Error: " . $e->getMessage());
        }

        return response()->json(['ok' => true, 'ride_id' => $ride->id, 'status' => $ride->status]);
    }


    public function cancelByPassenger(Request $request, int $id)
    {
        /** @var User|null $user */
        $user = Auth::user();
        $ride = Ride::findOrFail($id);
        if ($ride->rider_id !== ($user?->id) || !in_array($ride->status, ['requested', 'accepted'])) {
            return response()->json(['message' => 'Invalid state'], 422);
        }
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:120'],
        ]);
        $ride->status = 'cancelled';
        $ride->cancelled_at = now();
        $ride->cancellation_reason = $data['reason'] ?? null;
        $ride->save();

        broadcast(new RideCancelled($ride, 'passenger', $user));

        // Notify driver
        try {
            $driver = $ride->driver;
            if ($driver) {
                $fcm = app(FcmService::class);
                $fcm->sendToUser(
                    $driver,
                    "Course annulée",
                    "Le passager a annulé la course.",
                    ['ride_id' => (string) $ride->id, 'type' => 'ride_cancelled']
                );
            }
        } catch (\Exception $e) {
            \Log::error("FCM Ride Cancelled By Passenger Error: " . $e->getMessage());
        }

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
            'online' => ['required', 'boolean'],
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

        $ride = Ride::with('rating')->findOrFail($id);
        if ($ride->driver_id !== $driver->id) {
            return response()->json(['message' => 'Not your ride'], 403);
        }

        if ($ride) {
            $breakdown = $this->calculateRideFareBreakdown($ride);
            $ride->fare_amount = $breakdown['total_fare'];
            $data = array_merge($ride->toArray(), $breakdown);
            // Map rating.stars to rating for frontend
            $data['rating'] = $ride->rating ? $ride->rating->stars : null;
            return response()->json($data);
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
            ->where(function ($q) {
                $q->whereIn('status', ['requested', 'accepted', 'arrived', 'started', 'ongoing'])
                    ->orWhere(function ($sq) {
                        $sq->where('status', 'completed')
                            ->where('completed_at', '>=', now()->subMinutes(15))
                            ->where(function ($sq2) {
                                $sq2->where('payment_status', '!=', 'completed')
                                    ->whereDoesntHave('rating');
                            });
                    });
            })
            ->with(['driver.driverProfile', 'rating'])
            ->orderByDesc('id')
            ->first();

        if ($ride) {
            $breakdown = $this->calculateRideFareBreakdown($ride);
            $ride->fare_amount = $breakdown['total_fare'];
            $data = array_merge($ride->toArray(), $breakdown);
            return response()->json($data);
        }
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
        $ride = Ride::with('driver.driverProfile')->findOrFail($id);
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
                'photo' => $ride->driver->photo,
                'vehicle' => $ride->driver->driverProfile ? [
                    'make' => $ride->driver->driverProfile->vehicle_make,
                    'model' => $ride->driver->driverProfile->vehicle_model,
                    'year' => $ride->driver->driverProfile->vehicle_year,
                    'color' => $ride->driver->driverProfile->vehicle_color,
                    'license_plate' => $ride->driver->driverProfile->license_plate,
                    'type' => $ride->driver->driverProfile->vehicle_type,
                ] : null,
            ] : null,
            'passenger_name' => $ride->passenger_name,
            'passenger_phone' => $ride->passenger_phone,
            'stop_started_at' => $ride->stop_started_at,
            'total_stop_duration_s' => $ride->total_stop_duration_s,
            ...$this->calculateRideFareBreakdown($ride),
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
                    ->whereIn('status', ['accepted', 'arrived', 'pickup', 'ongoing']);
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
                    ->where(function ($q) use ($driver) {
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
            'duration_s' => (int) ($ride->duration_s ?? 0),
            'distance_m' => (int) ($ride->distance_m ?? 0),
            'service_type' => $ride->service_type,
            'accepted_at' => $ride->accepted_at,
            'started_at' => $ride->started_at,
            'completed_at' => $ride->completed_at,
            'rider' => $this->formatPassenger($passenger),
            'passenger_name' => $ride->passenger_name,
            'passenger_phone' => $ride->passenger_phone,
            'stop_started_at' => $ride->stop_started_at,
            'total_stop_duration_s' => $ride->total_stop_duration_s,
            'payment_method' => $ride->payment_method,
            ...$this->calculateRideFareBreakdown($ride),
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
        // OU une course sans coordonnées (créée manuellement par l'admin)
        $lat = $driver->last_lat;
        $lng = $driver->last_lng;

        if ($lat === null || $lng === null) {
            // Even without driver coordinates, show manual rides (no coords)
            $rides = Ride::query()
                ->where('status', 'requested')
                ->where(function ($q) {
                    $q->whereNull('pickup_lat')->orWhereNull('pickup_lng');
                });

            // Exclure les courses déjà déclinées par ce chauffeur
            $rides->where(function ($q) use ($driver) {
                $q->whereNull('declined_driver_ids')
                    ->orWhereRaw("NOT JSON_CONTAINS(declined_driver_ids, CAST(? AS JSON))", [json_encode($driver->id)]);
            });

            $rides = $rides->orderByDesc('id')->limit(5)->get();

            if ($rides->isEmpty()) {
                return response()->json([], 200);
            }

            $formattedRides = $rides->map(function ($ride) {
                $passenger = $ride->rider_id ? User::find($ride->rider_id) : null;
                return [
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
                    'passenger_name' => $ride->passenger_name,
                    'passenger_phone' => $ride->passenger_phone,
                    'vehicle_type' => $ride->vehicle_type,
                    'has_baggage' => (bool) $ride->has_baggage,
                    'service_type' => $ride->service_type,
                    'payment_method' => $ride->payment_method,
                ];
            });

            return response()->json($formattedRides);
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

        $rides = Ride::query()
            ->where('status', 'requested')
            ->where(function ($q) use ($distanceFormula, $searchRadiusKm) {
                // Rides with coordinates within radius
                $q->where(function ($sub) use ($distanceFormula, $searchRadiusKm) {
                    $sub->whereNotNull('pickup_lat')
                        ->whereNotNull('pickup_lng')
                        ->whereRaw("{$distanceFormula} <= ?", [$searchRadiusKm]);
                })
                    // OR rides without coordinates (manual admin rides)
                    ->orWhere(function ($sub) {
                    $sub->whereNull('pickup_lat')->orWhereNull('pickup_lng');
                });
            });

        // Exclure les courses déjà déclinées par ce chauffeur
        $rides->where(function ($q) use ($driver) {
            $q->whereNull('declined_driver_ids')
                ->orWhereRaw("NOT JSON_CONTAINS(declined_driver_ids, CAST(? AS JSON))", [json_encode($driver->id)]);
        });

        $rides = $rides->orderByDesc('id')->limit(5)->get();

        if ($rides->isEmpty()) {
            return response()->json([], 200);
        }

        $formattedRides = $rides->map(function ($ride) {
            $passenger = $ride->rider_id ? User::find($ride->rider_id) : null;
            return [
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
                'passenger_name' => $ride->passenger_name,
                'passenger_phone' => $ride->passenger_phone,
                'vehicle_type' => $ride->vehicle_type,
                'has_baggage' => (bool) $ride->has_baggage,
                'service_type' => $ride->service_type,
                'payment_method' => $ride->payment_method,
            ];
        });

        return response()->json($formattedRides);
    }

    public function updateDriverLocation(Request $request)
    {
        /** @var User|null $driver */
        $driver = Auth::user();
        if (!$driver || !$driver->isDriver()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }



        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
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
                    'stop_started_at' => $ride->stop_started_at,
                    'total_stop_duration_s' => $ride->total_stop_duration_s,
                    ...$this->calculateRideFareBreakdown($ride),
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

        $ids = array_filter(array_map(fn($id) => $id ? (int) $id : null, $ids));

        return array_values(array_unique($ids));
    }

    public function nearbyDrivers(Request $request)
    {
        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'radius' => ['nullable', 'numeric', 'min:1', 'max:50'],
        ]);

        $lat = (float) $data['lat'];
        $lng = (float) $data['lng'];
        $radius = (float) ($data['radius'] ?? config('app.search_radius_km', 10.0));

        $earthRadiusKm = 6371.0;
        $distanceFormula = "(
            {$earthRadiusKm} * 2 * ASIN(
                SQRT(
                    POWER(SIN(RADIANS({$lat} - users.last_lat) / 2), 2) +
                    COS(RADIANS({$lat})) * COS(RADIANS(users.last_lat)) *
                    POWER(SIN(RADIANS({$lng} - users.last_lng) / 2), 2)
                )
            )
        )";

        $drivers = User::query()
            ->where('role', 'driver')
            ->where('is_online', true)
            ->where('is_active', true)
            ->join('driver_profiles', 'driver_profiles.user_id', '=', 'users.id')
            ->where('driver_profiles.status', 'approved')
            ->whereNotNull('users.last_lat')
            ->whereNotNull('users.last_lng')
            ->whereRaw("{$distanceFormula} <= ?", [$radius])
            ->select('users.id', 'users.last_lat as lat', 'users.last_lng as lng', 'users.last_location_at')
            ->selectRaw("{$distanceFormula} as distance_km")
            ->orderByRaw("{$distanceFormula} ASC")
            ->limit(10)
            ->get();

        return response()->json([
            'drivers' => $drivers,
            'count' => $drivers->count(),
        ]);
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

    /**
     * Start a stop/wait period (Driver action)
     */
    public function startStop(Request $request, int $id)
    {
        /** @var User|null $driver */
        $driver = Auth::user();
        $ride = Ride::findOrFail($id);

        if ($ride->driver_id !== ($driver?->id) || $ride->status !== 'ongoing') {
            return response()->json(['message' => 'Invalid state'], 422);
        }

        if ($ride->stop_started_at) {
            return response()->json(['message' => 'Stop already started'], 422);
        }

        $ride->stop_started_at = now();
        $ride->save();

        broadcast(new RideStopUpdated($ride));

        return response()->json(['ok' => true, 'stop_started_at' => $ride->stop_started_at]);
    }

    /**
     * End a stop/wait period (Driver action)
     */
    public function endStop(Request $request, int $id)
    {
        /** @var User|null $driver */
        $driver = Auth::user();
        $ride = Ride::findOrFail($id);

        if ($ride->driver_id !== ($driver?->id) || $ride->status !== 'ongoing' || !$ride->stop_started_at) {
            return response()->json(['message' => 'Invalid state'], 422);
        }

        $duration = (int) now()->diffInSeconds($ride->stop_started_at, true);
        $ride->total_stop_duration_s += $duration;
        $ride->stop_started_at = null;
        $ride->save();

        broadcast(new RideStopUpdated($ride));

        return response()->json(['ok' => true, 'total_stop_duration_s' => $ride->total_stop_duration_s]);
    }

    /**
     * Get pricing configuration (cached)
     */
    protected function getPricingConfig()
    {
        return Cache::remember('pricing.config', 60, function () {
            $s = PricingSetting::query()->first();
            return [
                'base_fare' => (int) ($s?->base_fare ?? 500),
                'per_km' => (int) ($s?->per_km ?? 250),
                'min_fare' => (int) ($s?->min_fare ?? 1000),
                'luggage_unit_price' => (int) ($s?->luggage_unit_price ?? 500),
                'platform_pct' => (int) ($s?->platform_commission_pct ?? 70),
                'driver_pct' => (int) ($s?->driver_commission_pct ?? 20),
                'maintenance_pct' => (int) ($s?->maintenance_commission_pct ?? 10),
                'stop_rate_per_min' => (int) ($s?->stop_rate_per_min ?? 5),
                'weather' => [
                    'enabled' => (bool) ($s?->weather_mode_enabled ?? false),
                    'multiplier' => (float) ($s?->weather_multiplier ?? 1.0),
                ],
                'night' => [
                    'multiplier' => (float) ($s?->night_multiplier ?? 1.0),
                    'start_time' => substr((string) ($s?->night_start_time ?? '22:00'), 0, 5),
                    'end_time' => substr((string) ($s?->night_end_time ?? '06:00'), 0, 5),
                ],
                'peak_hours' => [
                    'enabled' => (bool) ($s?->peak_hours_enabled ?? false),
                    'multiplier' => (float) ($s?->peak_hours_multiplier ?? 1.0),
                    'start_time' => substr((string) ($s?->peak_hours_start_time ?? '17:00'), 0, 5),
                    'end_time' => substr((string) ($s?->peak_hours_end_time ?? '20:00'), 0, 5),
                ],
                'pickup_grace_period_m' => (int) ($s?->pickup_grace_period_m ?? 5),
                'pickup_waiting_rate_per_min' => (int) ($s?->pickup_waiting_rate_per_min ?? 10),
            ];
        });
    }

    /**
     * Calculate live fare breakdown for a ride
     */
    protected function calculateRideFareBreakdown(Ride $ride)
    {
        $pricing = $this->getPricingConfig();

        // 1. Calculate trajectory price (Base + Distance)
        $distanceKm = ($ride->distance_m ?? 0) / 1000.0;
        $baseFare = (int) $pricing['base_fare'];
        $distanceFare = (int) round($distanceKm * $pricing['per_km']);

        $trajectoryPrice = $baseFare + $distanceFare;

        // Apply multipliers to trajectory only
        if ($ride->vehicle_type === 'vip') {
            $trajectoryPrice *= 1.5;
        }

        if ($pricing['peak_hours']['enabled'] && $this->isCurrentlyInTimeRange($pricing['peak_hours']['start_time'], $pricing['peak_hours']['end_time'])) {
            $trajectoryPrice *= $pricing['peak_hours']['multiplier'];
        }

        if ($pricing['weather']['enabled']) {
            $trajectoryPrice *= $pricing['weather']['multiplier'];
        }

        if ($pricing['night']['multiplier'] > 1.0 && $this->isCurrentlyInTimeRange($pricing['night']['start_time'], $pricing['night']['end_time'])) {
            $trajectoryPrice *= $pricing['night']['multiplier'];
        }

        // Ensure trajectory meets minimum fare
        $trajectoryPrice = max($pricing['min_fare'], (int) round($trajectoryPrice));

        // 2. Calculate stop price
        $totalStopDuration = (int) ($ride->total_stop_duration_s ?? 0);
        if ($ride->stop_started_at) {
            $totalStopDuration += (int) now()->diffInSeconds($ride->stop_started_at, true);
        }

        $stopMinutes = floor($totalStopDuration / 60.0);
        $stopPrice = (int) ($stopMinutes * ($pricing['stop_rate_per_min'] ?? 5));

        // 3. Calculate pickup waiting price
        $pickupWaitingPrice = 0;
        $pickupWaitMinutes = 0;
        if ($ride->arrived_at) {
            $endWait = $ride->started_at ?? now();
            $waitSeconds = (int) $endWait->diffInSeconds($ride->arrived_at, true);
            $graceSeconds = ($pricing['pickup_grace_period_m'] ?? 5) * 60;

            if ($waitSeconds > $graceSeconds) {
                $pickupWaitMinutes = floor(($waitSeconds - $graceSeconds) / 60.0);
                $pickupWaitingPrice = (int) ($pickupWaitMinutes * ($pricing['pickup_waiting_rate_per_min'] ?? 10));
            }
        }

        $totalFare = $trajectoryPrice + $stopPrice + $pickupWaitingPrice;

        // 4. Luggage Fee
        $luggageCount = (int) ($ride->luggage_count ?? ($ride->has_baggage ? 1 : 0));
        $luggagePrice = $luggageCount * ($pricing['luggage_unit_price'] ?? 500);
        $totalFare += $luggagePrice;

        return [
            'base_fare' => $baseFare,
            'distance_fare' => $distanceFare,
            'luggage_fare' => $luggagePrice,
            'wait_fare' => $stopPrice + $pickupWaitingPrice,
            'duration_fare' => $stopPrice + $pickupWaitingPrice, // Alias for app compatibility
            'pickup_waiting_fare' => $pickupWaitingPrice,
            'stop_waiting_fare' => $stopPrice,
            'total_fare' => $totalFare,
            'wait_duration_m' => (int) ($stopMinutes + $pickupWaitMinutes),
        ];
    }

    /**
     * Check if current time is within a range (handles overnight ranges)
     */
    protected function isCurrentlyInTimeRange(string $start, string $end): bool
    {
        $nowTime = now()->format('H:i');
        if ($start <= $end) {
            return $nowTime >= $start && $nowTime <= $end;
        } else {
            return $nowTime >= $start || $nowTime <= $end;
        }
    }
}
