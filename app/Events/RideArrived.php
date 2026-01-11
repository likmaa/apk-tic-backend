<?php

namespace App\Events;

use App\Models\Ride;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Queue\SerializesModels;

class RideArrived implements ShouldBroadcastNow
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
            new PrivateChannel('private-ride.' . $this->ride->id),
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