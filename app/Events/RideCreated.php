<?php

namespace App\Events;

use App\Models\Ride;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RideCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $ride;

    public function __construct(Ride $ride)
    {
        $this->ride = $ride;
    }

    public function broadcastOn(): array
    {
        return [
            new Channel('rides'),
            new PrivateChannel('user.'.$this->ride->driver_id)
        ];
    }

    public function broadcastAs()
    {
        return 'ride.created';
    }

    public function broadcastWith(): array
    {
        return [
            'ride' => [
                'id' => $this->ride->id,
                'driver_id' => $this->ride->driver_id,
                'pickup_address' => $this->ride->pickup_address,
                'destination_address' => $this->ride->destination_address,
                'departure_time' => $this->ride->departure_time->toISOString(),
                'available_seats' => $this->ride->available_seats,
                'price_per_seat' => $this->ride->price_per_seat,
                'vehicle_type' => $this->ride->vehicle_type,
            ]
        ];
    }
}
