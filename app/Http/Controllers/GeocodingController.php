<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;

class GeocodingController extends Controller
{
    public function search(Request $request)
    {
        $validated = $request->validate([
            'query' => ['required', 'string', 'min:2'],
            'language' => ['sometimes', 'string', 'size:2'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:10'],
        ]);

        $query = $validated['query'];
        $language = $validated['language'] ?? 'fr';
        $limit = $validated['limit'] ?? 8;

        $cacheKey = "geocode:search:" . md5($language.'|'.$limit.'|'.$query);
        $started = microtime(true);
        $ip = $request->ip();
        $uid = optional($request->user())->id;

        $results = Cache::remember($cacheKey, 3600, function () use ($query, $language, $limit) {
            $url = 'https://nominatim.openstreetmap.org/search';
            $resp = Http::timeout(6)
                ->withHeaders([
                    'Accept' => 'application/json',
                    'User-Agent' => 'PortoBackend/1.0 (geocoding search)',
                ])
                ->get($url, [
                    'q' => $query,
                    'format' => 'jsonv2',
                    'addressdetails' => 1,
                    'limit' => $limit,
                    'accept-language' => $language,
                    'countrycodes' => 'bj', // ne renvoyer que des résultats au Bénin
                ]);
            if (!$resp->ok()) return ['items' => [], 'status' => $resp->status()];
            $json = $resp->json();
            $items = is_array($json) ? $json : [];
            $mapped = [];
            foreach ($items as $f) {
                $mapped[] = [
                    'place_id' => (string)($f['place_id'] ?? ''),
                    'display_name' => (string)($f['display_name'] ?? ''),
                    'lat' => isset($f['lat']) ? (string)$f['lat'] : '',
                    'lon' => isset($f['lon']) ? (string)$f['lon'] : '',
                ];
            }
            return ['items' => $mapped, 'status' => $resp->status()];
        });

        $duration = (int) round((microtime(true) - $started) * 1000);
        try {
            DB::table('geocoding_logs')->insert([
                'user_id' => $uid,
                'ip' => $ip,
                'type' => 'search',
                'query' => $query,
                'lat' => null,
                'lon' => null,
                'provider' => 'nominatim',
                'status' => $results['status'] ?? null,
                'duration_ms' => $duration,
                'result_count' => isset($results['items']) ? count($results['items']) : 0,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {}

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

        $cacheKey = "geocode:reverse:" . md5($language.'|'.$lat.'|'.$lon);
        $started = microtime(true);
        $ip = $request->ip();
        $uid = optional($request->user())->id;

        $data = Cache::remember($cacheKey, 3600, function () use ($lat, $lon, $language) {
            $url = 'https://nominatim.openstreetmap.org/reverse';
            $resp = Http::timeout(6)
                ->withHeaders([
                    'Accept' => 'application/json',
                    'User-Agent' => 'PortoBackend/1.0 (geocoding reverse)',
                ])
                ->get($url, [
                    'lat' => $lat,
                    'lon' => $lon,
                    'format' => 'jsonv2',
                    'accept-language' => $language,
                    'addressdetails' => 1,
                ]);
            if (!$resp->ok()) return ['address' => null, 'label' => null, 'status' => $resp->status()];
            $json = $resp->json();
            $addr = $json['display_name'] ?? null;

            $label = null;
            $addressDetails = isset($json['address']) && is_array($json['address']) ? $json['address'] : [];
            $name = $json['name'] ?? null;
            $road = $addressDetails['road'] ?? null;
            $houseNumber = $addressDetails['house_number'] ?? null;
            $neighbourhood = $addressDetails['neighbourhood'] ?? ($addressDetails['suburb'] ?? null);

            if (is_string($name) && $name !== '') {
                $label = $name;
            } elseif ($road || $houseNumber) {
                $label = trim(trim((string)($houseNumber ? $houseNumber . ' ' : '')) . (string)($road ?? ''));
            } elseif ($neighbourhood) {
                $label = (string)$neighbourhood;
            } else {
                $label = $addr;
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
                'provider' => 'nominatim',
                'status' => $data['status'] ?? null,
                'duration_ms' => $duration,
                'result_count' => isset($data['address']) && $data['address'] ? 1 : 0,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {}

        return response()->json([
            'address' => $data['address'] ?? null,
            'label' => $data['label'] ?? null,
        ]);
    }
}
