<?php

namespace App\Repositories;

use App\Interfaces\RideRepositoryInterface;
use App\Models\Ride;
use App\Models\Booking;
use App\Services\OpenRouteService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Carbon\Carbon;

class RideRepository implements RideRepositoryInterface
{
    public function __construct(
        private OpenRouteService $routingService
    ) {}

    /**
     * Create a new ride with geocoding and routing details.
     *
     * @param array $data
     * @return Ride
     * @throws \Exception
     */
    public function createRide(array $data): Ride
    {
        return DB::transaction(function () use ($data) {
            // 1. Pickup
            if (isset($data['pickup_lat'], $data['pickup_lng'])) {
                $pickup = [
                    'lat'   => (float)$data['pickup_lat'],
                    'lng'   => (float)$data['pickup_lng'],
                ];
                // reverse-geocode to get a label
                $pickupLabel = $this->routingService->reverseGeocode($pickup['lat'], $pickup['lng']);
            } else {
                $pickup = $this->routingService->geocodeAddress($data['pickup_address']);
                $pickupLabel = $pickup['label'];
            }

            // 2. Destination
            if (isset($data['destination_lat'], $data['destination_lng'])) {
                $destination = [
                    'lat' => (float)$data['destination_lat'],
                    'lng' => (float)$data['destination_lng'],
                ];
                $destinationLabel = $this->routingService->reverseGeocode(
                    $destination['lat'], $destination['lng']
                );
            } else {
                $destination = $this->routingService->geocodeAddress($data['destination_address']);
                $destinationLabel = $destination['label'];
            }

            // 3. Route summary
            $route = $this->routingService->getRouteDetails($pickup, $destination);

            // 4. Persist with human labels
            $attributes = [
                'driver_id'           => $data['driver_id'],
                'pickup_address'      => $pickupLabel,
                'destination_address' => $destinationLabel,
                'distance'            => $route['distance'],
                'duration'            => $route['duration'],
                'departure_time'      => Carbon::parse($data['departure_time'])->toDateTimeString(),
                'available_seats'     => $data['available_seats'],
                'price_per_seat'      => $data['price_per_seat'],
                'vehicle_type'        => $data['vehicle_type'],
                'notes'               => $data['notes'] ?? null,

                'pickup_location'      => DB::raw(sprintf(
                    "ST_GeomFromText('POINT(%F %F)',4326)",
                    $pickup['lng'], $pickup['lat']
                )),
                'destination_location' => DB::raw(sprintf(
                    "ST_GeomFromText('POINT(%F %F)',4326)",
                    $destination['lng'], $destination['lat']
                )),
            ];

            $ride = new Ride();
            $ride->setRawAttributes($attributes, true);
            $ride->save();
            return $ride->fresh();
        });
    }

    /**
     * Fetch upcoming rides.
     *
     * @return Collection
     */
    public function getUpcomingRides(): Collection
    {
        Log::info('RideRepository: Fetching upcoming rides');
        return Ride::with('driver.profile')
            ->where('departure_time', '>', now())
            ->orderBy('departure_time', 'asc')
            ->get();
    }

    /**
     * Get ride by ID.
     *
     * @param  int  $rideId
     * @return Ride
     * @throws ModelNotFoundException
     */
    public function getRideById(int $rideId): Ride
    {
        Log::info('RideRepository: Fetch ride by ID', ['ride_id' => $rideId]);
        return Ride::with(['driver.profile', 'bookings.user'])->findOrFail($rideId);
    }

    /**
     * Update a ride.
     *
     * @param  int   $rideId
     * @param  array $data
     * @return Ride
     * @throws \Exception
     */
    public function updateRide(int $rideId, array $data): Ride
    {
        Log::info('RideRepository: Updating ride', ['ride_id' => $rideId]);

        return DB::transaction(function () use ($rideId, $data) {
            $ride = Ride::findOrFail($rideId);

            // Normalize departure_time if provided
            if (!empty($data['departure_time'])) {
                $data['departure_time'] = Carbon::parse($data['departure_time'])->toDateTimeString();
            }

            // Handle spatial changes
            $rawUpdates = [];
            if (isset($data['pickup_lat'], $data['pickup_lng'])) {
                $rawUpdates['pickup_location'] = DB::raw(
                    sprintf("ST_GeomFromText('POINT(%F %F)',4326)", $data['pickup_lng'], $data['pickup_lat'])
                );
                unset($data['pickup_lat'], $data['pickup_lng']);
            }
            if (isset($data['destination_lat'], $data['destination_lng'])) {
                $rawUpdates['destination_location'] = DB::raw(
                    sprintf("ST_GeomFromText('POINT(%F %F)',4326)", $data['destination_lng'], $data['destination_lat'])
                );
                unset($data['destination_lat'], $data['destination_lng']);
            }

            // Fill remaining attributes
            $ride->fill($data);

            // Merge raw updates if any
            if ($rawUpdates) {
                $current = $ride->getAttributes();
                $ride->setRawAttributes(array_merge($current, $rawUpdates), true);
            }

            $ride->save();
            Log::info('RideRepository: Ride updated', ['ride_id' => $ride->id]);
            return $ride->fresh();
        });
    }

    /**
     * Delete a ride.
     *
     * @param  int  $rideId
     * @return bool
     */
    public function deleteRide(int $rideId): bool
    {
        Log::info('RideRepository: Deleting ride', ['ride_id' => $rideId]);
        return (bool) Ride::destroy($rideId);
    }

    /**
     * Get rides for a specific driver.
     *
     * @param  int  $userId
     * @return Collection
     */
    public function getDriverRides(int $userId): Collection
    {
        Log::info('RideRepository: Fetching driver rides', ['user_id' => $userId]);
        return Ride::where('driver_id', $userId)
            ->withCount('bookings')
            ->orderBy('departure_time', 'desc')
            ->get();
    }

    /**
     * Book seats on a ride.
     *
     * @param  int   $rideId
     * @param  array $bookingData
     * @return Booking
     * @throws \Exception
     */
    public function bookRide(int $rideId, array $bookingData): Booking
    {
        Log::info('RideRepository: Booking ride', [
            'ride_id' => $rideId,
            'user_id' => $bookingData['user_id'] ?? null,
            'seats'   => $bookingData['seats']
        ]);

        return DB::transaction(function () use ($rideId, $bookingData) {
            $ride = Ride::lockForUpdate()->findOrFail($rideId);

            if ($ride->available_seats < $bookingData['seats']) {
                throw new \Exception('Not enough available seats', 400);
            }

            // 🔧 Ensure ride_id is included explicitly
            $booking = Booking::create([
                'user_id' => $bookingData['user_id'],
                'ride_id' => $rideId,
                'seats'   => $bookingData['seats'],
            ]);

            $ride->decrement('available_seats', $bookingData['seats']);

            Log::info('RideRepository: Ride booked', ['booking_id' => $booking->id]);

            return $booking->load('user', 'ride');
        });
    }


    /**
     * Search rides based on criteria.
     *
     * @param  array $criteria
     * @return Collection
     */
    public function searchRides(array $criteria): Collection
    {
        Log::info('RideRepository: Searching rides', $criteria);

        $query = Ride::query()->with('driver.profile');

        if (!empty($criteria['from']['lat']) && !empty($criteria['from']['lng'])) {
            $lat    = (float) $criteria['from']['lat'];
            $lng    = (float) $criteria['from']['lng'];
            $radius = ((int) ($criteria['radius'] ?? 10)) * 1000;
            $point  = sprintf("POINT(%F %F)", $lng, $lat);

            $query->whereRaw(
                "ST_Distance_Sphere(pickup_location, ST_GeomFromText(?,4326)) <= ?",
                [$point, $radius]
            );
        }

        if (!empty($criteria['departure_after'])) {
            $query->where('departure_time', '>=', Carbon::parse($criteria['departure_after'])->toDateTimeString());
        }

        if (!empty($criteria['seats'])) {
            $query->where('available_seats', '>=', (int) $criteria['seats']);
        }

        return $query->orderBy('departure_time', 'asc')->get();
    }
}
