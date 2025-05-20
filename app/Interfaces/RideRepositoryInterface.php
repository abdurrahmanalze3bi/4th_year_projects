<?php

namespace App\Interfaces;

use App\Models\Booking;
use App\Models\Ride;
use Illuminate\Database\Eloquent\Collection;

interface RideRepositoryInterface
{
    public function createRide(array $data): Ride;

    public function getUpcomingRides(): Collection;

    // Match the parameter type and return type
    public function getRideById(int $rideId): Ride;

    public function updateRide(int $rideId, array $data): Ride;

    public function deleteRide(int $rideId): bool;

    public function getDriverRides(int $userId): Collection;

    public function bookRide(int $rideId, array $bookingData): Booking;

    public function searchRides(array $criteria): Collection;
}
