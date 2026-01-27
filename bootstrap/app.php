<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Broadcast;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withBroadcasting(__DIR__ . '/../routes/channels.php')
    ->withMiddleware(function (Middleware $middleware): void {
        // Enable CORS globally (including preflight OPTIONS requests)
        $middleware->use([
            \Illuminate\Http\Middleware\HandleCors::class,
        ]);

        // Register route middleware aliases
        $middleware->alias([
            'role' => \App\Http\Middleware\RoleMiddleware::class,
            'driver.approved' => \App\Http\Middleware\EnsureDriverApproved::class,
        ]);

        $middleware->validateCsrfTokens(except: [
            'api/webhooks/kkiapay',
        ]);
    })
    ->withSchedule(function ($schedule) {
        $schedule->command('rides:expire')->everyMinute();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
