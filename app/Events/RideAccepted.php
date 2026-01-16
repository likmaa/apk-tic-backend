<?php

namespace App\Events;

use App\Models\Ride;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Queue\SerializesModels;

class RideAccepted implements ShouldBroadcast
{
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public Ride $ride)
    {
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('rider.'.$this->ride->rider_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'ride.accepted';
    }

    public function broadcastWith(): array
    {
        $driver = $this->ride->driver;

        return [
            'rideId' => $this->ride->id,
            'driver' => $driver ? [
                'id' => $driver->id,
                'name' => $driver->name,
                'phone' => $driver->phone,
                'vehicle_number' => $driver->vehicle_number,
                'photo' => $driver->photo,
            ] : null,
        ];
    }
}

