<?php

namespace App\Interfaces;
use App\Models\Booking;
use App\Models\Ride;
use Illuminate\Database\Eloquent\Collection;
// app/Interfaces/RideRepositoryInterface.php
interface RideRepositoryInterface
{
    public function createRide(array $data): Ride;
    public function getUpcomingRides(): Collection;
    public function getRideById($rideId): Ride;
    public function updateRide($rideId, array $data): Ride;
    public function deleteRide($rideId): bool;
    public function getDriverRides($userId): Collection;
    public function bookRide($rideId, array $bookingData): Booking;
    public function searchRides(array $criteria): Collection;
}
