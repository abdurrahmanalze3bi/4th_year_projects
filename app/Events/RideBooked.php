<?php

namespace App\Events;

use App\Models\Ride;
use App\Models\Booking;
use App\Models\User;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RideBooked implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $ride;
    public $booking;
    public $user;

    public function __construct(Ride $ride, Booking $booking, User $user)
    {
        $this->ride = $ride;
        $this->booking = $booking;
        $this->user = $user;
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('user.'.$this->ride->driver_id),
            new PrivateChannel('user.'.$this->user->id)
        ];
    }

    public function broadcastAs()
    {
        return 'ride.booked';
    }

    public function broadcastWith(): array
    {
        return [
            'ride' => $this->ride->only(['id', 'pickup_address', 'destination_address']),
            'booking' => $this->booking->only(['id', 'seats', 'status']),
            'passenger' => $this->user->only(['id', 'first_name', 'last_name'])
        ];
    }
}
