<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Define a rate limiter for OTP endpoints
        RateLimiter::for('otp', function (Request $request) {
            $key = sprintf('otp:%s', $request->ip());
            // 5 requests per minute by IP (adjust as needed)
            return [Limit::perMinute(5)->by($key)];
        });
    }
}
