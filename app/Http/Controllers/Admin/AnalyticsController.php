<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class AnalyticsController extends Controller
{
    /**
     * Récupère les statistiques de reconnexion
     */
    public function reconnections(Request $request)
    {
        $period = $request->query('period', '7d');
        
        // Calculer la date de début selon la période
        $now = Carbon::now();
        $from = match($period) {
            '24h' => $now->copy()->subHours(24),
            '7d' => $now->copy()->subDays(7),
            '30d' => $now->copy()->subDays(30),
            default => $now->copy()->subDays(7),
        };

        // Vérifier si la table existe
        $tableExists = DB::getSchemaBuilder()->hasTable('analytics_reconnections');
        
        if (!$tableExists) {
            // Retourner des données vides si la table n'existe pas encore
            return response()->json([
                'total' => 0,
                'averageDuration' => 0,
                'averageSyncDuration' => 0,
                'successRate' => 0,
                'byAppType' => [
                    'driver' => 0,
                    'passenger' => 0,
                ],
                'recentEvents' => [],
            ]);
        }

        // Récupérer les événements
        $events = DB::table('analytics_reconnections')
            ->whereBetween('created_at', [$from, $now])
            ->orderBy('created_at', 'desc')
            ->get();

        $total = $events->count();
        
        if ($total === 0) {
            return response()->json([
                'total' => 0,
                'averageDuration' => 0,
                'averageSyncDuration' => 0,
                'successRate' => 0,
                'byAppType' => [
                    'driver' => 0,
                    'passenger' => 0,
                ],
                'recentEvents' => [],
            ]);
        }

        // Calculer les statistiques
        $averageDuration = $events->avg('duration_ms') / 1000; // en secondes
        $syncDurations = $events->whereNotNull('sync_duration_ms')->pluck('sync_duration_ms');
        $averageSyncDuration = $syncDurations->count() > 0 
            ? $syncDurations->avg() 
            : 0;
        
        $successful = $events->where('data_synced', true)->count();
        $successRate = ($successful / $total) * 100;

        $byAppType = [
            'driver' => $events->where('app_type', 'driver')->count(),
            'passenger' => $events->where('app_type', 'passenger')->count(),
        ];

        // Événements récents (20 derniers)
        $recentEvents = $events->take(20)->map(function ($event) {
            return [
                'id' => $event->id,
                'user_id' => $event->user_id,
                'ride_id' => $event->ride_id,
                'disconnected_at' => $event->disconnected_at,
                'reconnected_at' => $event->reconnected_at,
                'duration_ms' => $event->duration_ms,
                'data_synced' => (bool) $event->data_synced,
                'sync_duration_ms' => $event->sync_duration_ms,
                'app_type' => $event->app_type,
                'created_at' => $event->created_at,
            ];
        })->values();

        return response()->json([
            'total' => $total,
            'averageDuration' => round($averageDuration, 2),
            'averageSyncDuration' => round($averageSyncDuration, 0),
            'successRate' => round($successRate, 2),
            'byAppType' => $byAppType,
            'recentEvents' => $recentEvents,
        ]);
    }

    /**
     * Reçoit un événement de reconnexion depuis les apps mobiles
     */
    public function trackReconnection(Request $request)
    {
        $validated = $request->validate([
            'rideId' => 'required',
            'disconnectedAt' => 'required|integer',
            'reconnectedAt' => 'required|integer',
            'duration' => 'required|integer',
            'dataSynced' => 'required|boolean',
            'syncDuration' => 'nullable|integer',
        ]);

        // Vérifier si la table existe (doit être créée via migration)
        if (!DB::getSchemaBuilder()->hasTable('analytics_reconnections')) {
            return response()->json([
                'message' => 'Table analytics_reconnections n\'existe pas. Veuillez exécuter les migrations.',
            ], 500);
        }

        DB::table('analytics_reconnections')->insert([
            'user_id' => $request->user()->id,
            'ride_id' => $validated['rideId'],
            'disconnected_at' => Carbon::createFromTimestampMs($validated['disconnectedAt']),
            'reconnected_at' => Carbon::createFromTimestampMs($validated['reconnectedAt']),
            'duration_ms' => $validated['duration'],
            'data_synced' => $validated['dataSynced'],
            'sync_duration_ms' => $validated['syncDuration'] ?? null,
            'app_type' => $request->header('X-App-Type', 'driver'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['ok' => true]);
    }
}
