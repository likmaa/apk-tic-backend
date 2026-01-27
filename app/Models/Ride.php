<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Ride extends Model
{
    use HasFactory;

    protected $table = 'rides';

    protected $fillable = [
        'rider_id',
        'driver_id',
        'status',
        'fare_amount',
        'commission_amount',
        'driver_earnings_amount',
        'currency',
        'distance_m',
        'duration_s',
        'pickup_lat',
        'pickup_lng',
        'dropoff_lat',
        'dropoff_lng',
        'pickup_address',
        'dropoff_address',
        'offered_driver_id',
        'declined_driver_ids',
        'passenger_name',
        'passenger_phone',
        'accepted_at',
        'started_at',
        'completed_at',
        'cancelled_at',
        'cancellation_reason',
        'vehicle_type',
        'total_stop_duration_s',
        'stop_started_at',
        'arrived_at',
        'tip_amount',
        'payment_method',
        'service_type',
        'recipient_name',
        'recipient_phone',
        'package_description',
        'package_weight',
        'is_fragile',
        'luggage_count',
        'payment_status',
        'payment_link',
        'external_reference',
    ];

    protected $casts = [
        'accepted_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'stop_started_at' => 'datetime',
        'arrived_at' => 'datetime',
        'declined_driver_ids' => 'array',
        'has_baggage' => 'boolean',
    ];

    public function driver()
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function rider()
    {
        return $this->belongsTo(User::class, 'rider_id');
    }

    public function rating()
    {
        return $this->hasOne(Rating::class, 'ride_id');
    }
}
