<?php

namespace App\Events;

use App\Models\Ride;
use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RideCancelled implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $ride;
    public $bookings;
    public $user;

    public function __construct(Ride $ride, array $bookings, User $user)
    {
        $this->ride = $ride;
        $this->bookings = $bookings;
        $this->user = $user;
    }

    public function broadcastOn(): array
    {
        return [
            new Channel('rides'),
            new PrivateChannel('user.'.$this->user->id)
        ];
    }

    public function broadcastAs()
    {
        return 'ride.cancelled';
    }

    public function broadcastWith(): array
    {
        return [
            'ride_id' => $this->ride->id,
            'driver' => $this->user->only(['id', 'name']),
            'affected_bookings' => count($this->bookings),
            'cancellation_time' => now()->toISOString()
        ];
    }
}
