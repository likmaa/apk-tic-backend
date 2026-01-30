<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GeocodingController extends Controller
{
    public function search(Request $request)
    {
        $validated = $request->validate([
            'query' => ['required', 'string', 'min:2'],
            'language' => ['sometimes', 'string', 'size:2'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:10'],
            'lat' => ['sometimes', 'numeric'],
            'lon' => ['sometimes', 'numeric'],
        ]);

        $query = $validated['query'];
        $language = $validated['language'] ?? 'fr';
        $limit = $validated['limit'] ?? 8;
        $lat = $request->lat ? round((float) $request->lat, 2) : null;
        $lon = $request->lon ? round((float) $request->lon, 2) : null;

        $cacheKey = "geocode:search:" . md5($language . '|' . $query . '|' . $limit . '|' . $lat . '|' . $lon);
        $started = microtime(true);
        $ip = $request->ip();
        $uid = optional($request->user())->id;

        // Log the request parameters
        Log::info("Geocoding search request", ['query' => $request->query('query'), 'lat' => $request->lat, 'lon' => $request->lon]);

        $results = Cache::remember($cacheKey, 3600, function () use ($query, $language, $limit, $request) {
            $mapboxToken = env('MAPBOX_TOKEN');

            // On demande plus de résultats en interne (20 au lieu de 8) pour avoir plus de choix lors du tri par distance
            $internalLimit = 20;

            $combined = [];

            // 0. Recherche locale dans les quartiers de la base de données (prioritaire)
            $localNeighborhoods = \App\Models\Neighborhood::search($query, 10);
            foreach ($localNeighborhoods as $neighborhood) {
                $combined[] = [
                    'place_id' => 'local_' . $neighborhood->id,
                    'display_name' => $neighborhood->name . ' (' . $neighborhood->arrondissement . ', ' . $neighborhood->city . ')',
                    'lat' => (string) ($neighborhood->lat ?? '6.4969'),
                    'lon' => (string) ($neighborhood->lng ?? '2.6283'),
                    'source' => 'local',
                    'priority' => 0, // Highest priority
                ];
            }

            // 1. Appel Mapbox
            $mapboxUrl = "https://api.mapbox.com/geocoding/v5/mapbox.places/" . urlencode($query) . ".json";
            $mapboxParams = [
                'access_token' => $mapboxToken,
                'language' => $language,
                'limit' => $internalLimit,
                'country' => 'bj',
                'bbox' => '2.10,6.30,2.70,6.85',
            ];
            if ($request->has('lat') && $request->has('lon')) {
                $mapboxParams['proximity'] = $request->lon . ',' . $request->lat;
            }

            // 2. Appel Nominatim
            $nominatimUrl = 'https://nominatim.openstreetmap.org/search';
            $nominatimParams = [
                'q' => $query,
                'format' => 'jsonv2',
                'addressdetails' => 1,
                'limit' => $internalLimit,
                'accept-language' => $language,
                'countrycodes' => 'bj',
            ];

            $responses = Http::pool(fn($pool) => [
                $pool->as('mapbox')->timeout(4)->get($mapboxUrl, $mapboxParams),
                $pool->as('nominatim')->timeout(4)->withHeaders(['User-Agent' => 'PortoBackend/1.0'])->get($nominatimUrl, $nominatimParams),
            ]);

            // Traitement Mapbox
            if ($responses['mapbox']->ok()) {
                foreach ($responses['mapbox']->json()['features'] ?? [] as $f) {
                    $name = $f['text'] ?? '';
                    $context = $f['context'] ?? [];
                    $neighborhood = null;
                    foreach ($context as $c) {
                        if (strpos($c['id'] ?? '', 'neighborhood') !== false || strpos($c['id'] ?? '', 'locality') !== false) {
                            $neighborhood = $c['text'] ?? null;
                            break;
                        }
                    }
                    $combined[] = [
                        'place_id' => 'mb_' . ($f['id'] ?? ''),
                        'display_name' => $neighborhood ? "$name ($neighborhood)" : $name,
                        'lat' => (string) ($f['center'][1] ?? ''),
                        'lon' => (string) ($f['center'][0] ?? ''),
                        'source' => 'mapbox'
                    ];
                }
            }

            // Traitement Nominatim
            if ($responses['nominatim']->ok()) {
                foreach ($responses['nominatim']->json() ?? [] as $f) {
                    $addr = $f['address'] ?? [];
                    $neighborhood = $addr['neighbourhood'] ?? ($addr['suburb'] ?? ($addr['quarter'] ?? null));
                    $name = $f['name'] ?? ($f['display_name'] ?? '');

                    $shortName = current(explode(',', $name));

                    $combined[] = [
                        'place_id' => 'nom_' . ($f['place_id'] ?? ''),
                        'display_name' => $neighborhood ? "$shortName ($neighborhood)" : $shortName,
                        'lat' => (string) ($f['lat'] ?? ''),
                        'lon' => (string) ($f['lon'] ?? ''),
                        'source' => 'nominatim'
                    ];
                }
            }

            $unique = [];
            $seen = [];
            $userLat = $request->lat;
            $userLon = $request->lon;

            foreach ($combined as $item) {
                $slug = Str::slug($item['display_name']);

                // On privilégie les POIs (Points d'Intérêt) par rapport aux adresses génériques
                $item['distance'] = null;
                if ($userLat && $userLon && $item['lat'] && $item['lon']) {
                    // Haversine simple
                    $item['distance'] = sqrt(pow((float) $item['lat'] - (float) $userLat, 2) + pow((float) $item['lon'] - (float) $userLon, 2));
                }

                if (!isset($seen[$slug])) {
                    $unique[] = $item;
                    $seen[$slug] = true;
                }
            }

            if ($userLat && $userLon) {
                usort($unique, function ($a, $b) {
                    // First: prioritize local results (priority = 0)
                    $aPriority = $a['priority'] ?? 1;
                    $bPriority = $b['priority'] ?? 1;
                    if ($aPriority !== $bPriority) {
                        return $aPriority - $bPriority;
                    }
                    // Then: sort by distance
                    if ($a['distance'] === $b['distance'])
                        return 0;
                    if ($a['distance'] === null)
                        return 1;
                    if ($b['distance'] === null)
                        return -1;
                    return ($a['distance'] < $b['distance']) ? -1 : 1;
                });
            } else {
                // No user coordinates: just sort by priority
                usort($unique, function ($a, $b) {
                    return ($a['priority'] ?? 1) - ($b['priority'] ?? 1);
                });
            }

            return ['items' => array_slice($unique, 0, 15), 'status' => 200];
        });

        $duration = (int) round((microtime(true) - $started) * 1000);
        try {
            DB::table('geocoding_logs')->insert([
                'user_id' => $uid,
                'ip' => $ip,
                'type' => 'search',
                'query' => $query,
                'lat' => $request->lat,
                'lon' => $request->lon,
                'provider' => 'hybrid',
                'status' => $results['status'] ?? null,
                'duration_ms' => $duration,
                'result_count' => count($results['items'] ?? []),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
        }

        return response()->json([
            'results' => $results['items'] ?? [],
        ]);
    }

    public function reverse(Request $request)
    {
        $validated = $request->validate([
            'lat' => ['required', 'numeric'],
            'lon' => ['required', 'numeric'],
            'language' => ['sometimes', 'string', 'size:2'],
        ]);
        $lat = (float) $validated['lat'];
        $lon = (float) $validated['lon'];
        $language = $validated['language'] ?? 'fr';

        $cacheKey = "geocode:reverse:" . md5($language . '|' . $lat . '|' . $lon);
        $started = microtime(true);
        $ip = $request->ip();
        $uid = optional($request->user())->id;

        $data = Cache::remember($cacheKey, 3600, function () use ($lat, $lon, $language) {
            $token = env('MAPBOX_TOKEN');
            $url = "https://api.mapbox.com/geocoding/v5/mapbox.places/{$lon},{$lat}.json";

            $resp = Http::timeout(6)->get($url, [
                'access_token' => $token,
                'language' => $language,
                'limit' => 1,
                'types' => 'address,poi,neighborhood,locality'
            ]);

            if (!$resp->ok())
                return ['address' => null, 'label' => null, 'status' => $resp->status()];

            $json = $resp->json();
            $feature = $json['features'][0] ?? null;

            if (!$feature)
                return ['address' => null, 'label' => null, 'status' => $resp->status()];

            $addr = $feature['place_name'] ?? null;
            $name = $feature['text'] ?? null;

            // Extraction du quartier
            $context = $feature['context'] ?? [];
            $neighborhood = null;
            foreach ($context as $c) {
                if (strpos($c['id'] ?? '', 'neighborhood') !== false || strpos($c['id'] ?? '', 'locality') !== false) {
                    $neighborhood = $c['text'] ?? null;
                    break;
                }
            }

            $label = $name;
            if ($neighborhood && strpos($name, $neighborhood) === false) {
                $label = $name . ', ' . $neighborhood;
            }

            return [
                'address' => $addr,
                'label' => $label,
                'status' => $resp->status(),
            ];
        });

        $duration = (int) round((microtime(true) - $started) * 1000);
        try {
            DB::table('geocoding_logs')->insert([
                'user_id' => $uid,
                'ip' => $request->ip(),
                'type' => 'reverse',
                'query' => null,
                'lat' => $lat,
                'lon' => $lon,
                'provider' => 'mapbox',
                'status' => $data['status'] ?? null,
                'duration_ms' => $duration,
                'result_count' => isset($data['address']) && $data['address'] ? 1 : 0,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
        }

        return response()->json([
            'address' => $data['address'] ?? null,
            'label' => $data['label'] ?? null,
        ]);
    }
}
