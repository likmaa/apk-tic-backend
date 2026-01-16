<?php

namespace App\Events;

use App\Models\Ride;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Queue\SerializesModels;

class RideArrived implements ShouldBroadcast
{
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public Ride $ride)
    {
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('rider.' . $this->ride->rider_id),
            new PrivateChannel('ride.' . $this->ride->id), // Fixed: removed 'private-' prefix (Laravel adds it automatically)
        ];
    }

    public function broadcastAs(): string
    {
        return 'ride.arrived';
    }

    public function broadcastWith(): array
    {
        return [
            'rideId' => $this->ride->id,
            'arrived_at' => $this->ride->arrived_at?->toIso8601String(),
        ];
    }
}