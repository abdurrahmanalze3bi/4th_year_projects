<?php

namespace App\Repositories;

use App\Interfaces\RideRepositoryInterface;
use App\Interfaces\GeocodingServiceInterface;
use App\Models\Ride;
use App\Models\Booking;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RideRepository implements RideRepositoryInterface
{
    public function __construct(
        private GeocodingServiceInterface $geocodingService
    ) {}

    public function createRide(array $data): Ride
    {
        return DB::transaction(function () use ($data) {
            try {
                // Convert addresses to coordinates
                $pickup = $this->geocodingService->geocodeAddress($data['pickup_address']);
                $destination = $this->geocodingService->geocodeAddress($data['destination_address']);

                // Get route details
                $route = $this->geocodingService->getRouteDetails($pickup, $destination);

                return Ride::create([
                    'driver_id' => $data['driver_id'],
                    'pickup_address' => $data['pickup_address'],
                    'pickup_lat' => $pickup['lat'],
                    'pickup_lng' => $pickup['lng'],
                    'destination_address' => $data['destination_address'],
                    'destination_lat' => $destination['lat'],
                    'destination_lng' => $destination['lng'],
                    'distance' => $route['distance'],
                    'duration' => $route['duration'],
                    'route_geometry' => $route['geometry'],
                    'departure_time' => $data['departure_time'],
                    'available_seats' => $data['available_seats'],
                    'price_per_seat' => $data['price_per_seat'],
                    'vehicle_type' => $data['vehicle_type']
                ]);

            } catch (\Exception $e) {
                Log::error("Ride creation failed: {$e->getMessage()}");
                throw new \Exception("Could not create ride: {$e->getMessage()}");
            }
        });
    }

// app/Repositories/RideRepository.php
    public function getUpcomingRides(): \Illuminate\Database\Eloquent\Collection
    {
        return Ride::with('driver')
            ->where('departure_time', '>', now())
            ->orderBy('departure_time')
            ->get();
    }

    public function getRideById($rideId): Ride
    {
        return Ride::with(['driver', 'bookings.user'])
            ->findOrFail($rideId); // ✅ Correctly spelled
    }

    public function updateRide($rideId, array $data): Ride
    {
        $ride = Ride::findOrFail($rideId); // Fixed from findOrFall
        $ride->update($data);
        return $ride->fresh();
    }

    public function deleteRide($rideId): bool
    {
        return Ride::destroy($rideId) > 0;
    }

    public function getDriverRides($userId): \Illuminate\Database\Eloquent\Collection
    {
        return Ride::where('driver_id', $userId)
            ->withCount('bookings')
            ->orderBy('departure_time', 'desc')
            ->get();
    }

    public function bookRide($rideId, array $bookingData): Booking
    {
        return DB::transaction(function () use ($rideId, $bookingData) {
            $ride = Ride::lockForUpdate()->findOrFail($rideId);

            if ($ride->available_seats < $bookingData['seats']) {
                throw new \Exception('Not enough available seats');
            }

            $booking = $ride->bookings()->create($bookingData);
            $ride->decrement('available_seats', $bookingData['seats']);

            return $booking->load('user');
        });
    }

    public function searchRides(array $criteria): \Illuminate\Database\Eloquent\Collection
    {
        return Ride::query()
            ->when(isset($criteria['from']), function ($query) use ($criteria) {
                $query->nearLocation(
                    $criteria['from']['lat'],
                    $criteria['from']['lng'],
                    $criteria['radius'] ?? 10
                );
            })
            ->where('departure_time', '>=', now())
            ->where('available_seats', '>=', $criteria['seats'])
            ->orderBy('departure_time')
            ->get();
    }
}
