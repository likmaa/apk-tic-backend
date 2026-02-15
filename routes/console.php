<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('rides:expire', function () {
    $expiredRides = \App\Models\Ride::where('status', 'requested')
        ->where('created_at', '<', now()->subMinutes(10))
        ->get();

    /** @var \App\Models\Ride $ride */
    foreach ($expiredRides as $ride) {
        $ride->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'cancellation_reason' => 'timeout_no_driver'
        ]);

        broadcast(new \App\Events\RideCancelled($ride, 'system'));
        $this->info("Expired ride ID: {$ride->id}");
    }
})->purpose('Expire ride requests older than 5 minutes');

Artisan::command('drivers:expire-stale', function () {
    $threshold = now()->subMinutes(5);

    $staleDrivers = \App\Models\User::where('role', 'driver')
        ->where('is_online', true)
        ->where(function ($query) use ($threshold) {
            $query->whereNull('last_location_at')
                ->orWhere('last_location_at', '<', $threshold);
        })
        ->get();

    $count = $staleDrivers->count();

    /** @var \App\Models\User $driver */
    foreach ($staleDrivers as $driver) {
        $driver->update(['is_online' => false]);
        $this->info("Mise hors ligne du chauffeur #{$driver->id} ({$driver->name}) — dernière activité : " . ($driver->last_location_at ?? 'jamais'));
    }

    $this->info("{$count} chauffeur(s) mis hors ligne.");
})->purpose('Met hors ligne les chauffeurs sans activité GPS depuis 5 minutes');
