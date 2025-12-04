<?php

namespace App\Events;

use App\Models\Ride;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Queue\SerializesModels;

class RideCancelled implements ShouldBroadcast
{
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public Ride $ride,
        public string $cancelledBy,
        public ?User $actor = null
    ) {
        $this->ride->loadMissing('rider', 'driver');
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('admin.alerts'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'ride.cancelled';
    }

    public function broadcastWith(): array
    {
        return [
            'rideId' => $this->ride->id,
            'cancelled_by' => $this->cancelledBy,
            'actor' => $this->actor ? [
                'id' => $this->actor->id,
                'name' => $this->actor->name,
                'phone' => $this->actor->phone,
            ] : null,
            'status' => $this->ride->status,
            'reason' => $this->ride->cancellation_reason,
            'cancelled_at' => optional($this->ride->cancelled_at)->toIso8601String(),
            'pickup_address' => $this->ride->pickup_address,
            'dropoff_address' => $this->ride->dropoff_address,
        ];
    }
}

