<?php

namespace App\Services;

use App\Models\Ride;
use App\Models\User;
use App\Models\FcmToken;
use App\Events\RideRequested;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Config;

class RideService
{
    protected $fcm;

    public function __construct(FcmService $fcm)
    {
        $this->fcm = $fcm;
    }

    /**
     * Notifie les chauffeurs à proximité (FCM + Pusher).
     */
    public function notifyNearbyDrivers(Ride $ride)
    {
        // 1. Broadcast temps réel (Pusher/Websockets)
        try {
            broadcast(new RideRequested($ride));
        } catch (\Exception $e) {
            Log::error("Pusher Broadcast Error: " . $e->getMessage());
        }

        // 2. Notification Push Mobile (FCM)
        try {
            if ($ride->pickup_lat && $ride->pickup_lng) {
                // Geo-targeted: notify drivers within radius
                $radius = (float) config('app.search_radius_km', 10.0);
                $earthRadiusKm = 6371.0;

                $lat = $ride->pickup_lat;
                $lng = $ride->pickup_lng;

                $distanceFormula = "(
                    {$earthRadiusKm} * 2 * ASIN(
                        SQRT(
                            POWER(SIN(RADIANS({$lat} - users.last_lat) / 2), 2) +
                            COS(RADIANS({$lat})) * COS(RADIANS(users.last_lat)) *
                            POWER(SIN(RADIANS({$lng} - users.last_lng) / 2), 2)
                        )
                    )
                )";

                $nearbyDriverTokens = FcmToken::query()
                    ->join('users', 'users.id', '=', 'fcm_tokens.user_id')
                    ->join('driver_profiles', 'driver_profiles.user_id', '=', 'users.id')
                    ->where('users.role', 'driver')
                    ->where('users.is_online', true)
                    ->where('users.is_active', true)
                    ->where('driver_profiles.status', 'approved')
                    ->whereNotNull('users.last_lat')
                    ->whereNotNull('users.last_lng')
                    ->where('users.last_location_at', '>=', now()->subMinutes(5))
                    ->whereRaw("{$distanceFormula} <= ?", [$radius])
                    ->pluck('token')
                    ->unique()
                    ->toArray();
            } else {
                // Fallback: no coordinates — broadcast to ALL active drivers
                Log::info("FCM Fallback: No coordinates for Ride #{$ride->id}, broadcasting to all active drivers.");

                $nearbyDriverTokens = FcmToken::query()
                    ->join('users', 'users.id', '=', 'fcm_tokens.user_id')
                    ->join('driver_profiles', 'driver_profiles.user_id', '=', 'users.id')
                    ->where('users.role', 'driver')
                    ->where('users.is_online', true)
                    ->where('users.is_active', true)
                    ->where('driver_profiles.status', 'approved')
                    ->whereNotNull('users.last_lat')
                    ->whereNotNull('users.last_lng')
                    ->where('users.last_location_at', '>=', now()->subMinutes(5))
                    ->pluck('token')
                    ->unique()
                    ->toArray();
            }

            if (!empty($nearbyDriverTokens)) {
                $this->fcm->sendToTokens(
                    $nearbyDriverTokens,
                    "Nouvelle commande !",
                    "Une course à " . number_format($ride->fare_amount, 0, ',', ' ') . " FCFA est disponible à proximité.",
                    [
                        'ride_id' => (string) $ride->id,
                        'type' => 'new_ride',
                        'pickup_address' => (string) $ride->pickup_address,
                        'fare' => (string) $ride->fare_amount
                    ]
                );
                Log::info("FCM Sent to " . count($nearbyDriverTokens) . " drivers for Ride #{$ride->id}");
            }
        } catch (\Exception $e) {
            Log::error("FCM Driver Notification Error: " . $e->getMessage());
        }
    }
}
