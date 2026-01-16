<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('rides:expire', function () {
    $expiredRides = \App\Models\Ride::where('status', 'requested')
        ->where('created_at', '<', now()->subMinutes(5))
        ->get();

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
