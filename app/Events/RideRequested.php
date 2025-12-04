<?php

namespace App\Events;

use App\Models\Ride;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Queue\SerializesModels;

class RideRequested implements ShouldBroadcast
{
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public Ride $ride)
    {
    }

    public function broadcastOn(): array
    {
        return [
            new PresenceChannel('drivers'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'ride.requested';
    }

    public function broadcastWith(): array
    {
        return [
            'rideId' => $this->ride->id,
            'pickup' => [
                'lat' => $this->ride->pickup_lat,
                'lng' => $this->ride->pickup_lng,
                'address' => $this->ride->pickup_address,
            ],
            'dropoff' => [
                'lat' => $this->ride->dropoff_lat,
                'lng' => $this->ride->dropoff_lng,
                'address' => $this->ride->dropoff_address,
            ],
            'fare' => (int) ($this->ride->fare_amount ?? 0),
            'rider_id' => $this->ride->rider_id,
        ];
    }
}

