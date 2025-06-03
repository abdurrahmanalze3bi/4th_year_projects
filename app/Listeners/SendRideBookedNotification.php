<?php

namespace App\Listeners;

use App\Events\RideBooked;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class SendRideBookedNotification
{
    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(RideBooked $event)
    {
        // Handle notifications for driver and passenger
        $ride = $event->ride;
        $booking = $event->booking;

        // Existing notification logic in controller can move here
    }
}
