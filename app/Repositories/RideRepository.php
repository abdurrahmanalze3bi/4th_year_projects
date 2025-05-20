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
        Log::info('RideRepository: Creating ride', [
            'driver_id' => $data['driver_id'],
            'pickup_address' => $data['pickup_address'],
            'destination_address' => $data['destination_address'],
        ]);

        return DB::transaction(function () use ($data) {
            // 1. Geocode addresses
            $pickup      = $this->routingService->geocodeAddress($data['pickup_address']);
            $destination = $this->routingService->geocodeAddress($data['destination_address']);

            // 2. Get route summary
            $route = $this->routingService->getRouteDetails($pickup, $destination);

            // 3. Prepare base attributes, converting ISO8601 to MySQL DATETIME format
            $baseAttributes = [
                'driver_id'           => $data['driver_id'],
                'pickup_address'      => $data['pickup_address'],
                'destination_address' => $data['destination_address'],
                'distance'            => $route['distance'],
                'duration'            => $route['duration'],
                'departure_time'      => Carbon::parse($data['departure_time'])->toDateTimeString(),
                'available_seats'     => $data['available_seats'],
                'price_per_seat'      => $data['price_per_seat'],
                'vehicle_type'        => $data['vehicle_type'],
                'notes'               => $data['notes'] ?? null,
            ];

            // 4. Build raw spatial expressions
            $pickupPoint = DB::raw(sprintf(
                "ST_GeomFromText('POINT(%F %F)', 4326)",
                $pickup['lng'],
                $pickup['lat']
            ));
            $destinationPoint = DB::raw(sprintf(
                "ST_GeomFromText('POINT(%F %F)', 4326)",
                $destination['lng'],
                $destination['lat']
            ));

            // 5. Merge raw expressions into attributes
            $attributes = array_merge(
                $baseAttributes,
                [
                    'pickup_location'      => $pickupPoint,
                    'destination_location' => $destinationPoint,
                ]
            );

            // 6. Instantiate, set raw attributes to bypass mutators, and save
            $ride = new Ride();
            $ride->setRawAttributes($attributes, true);
            $ride->save();

            Log::info('RideRepository: Ride created', ['ride_id' => $ride->id]);
            return $ride;
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
        Log::info('RideRepository: Booking ride', ['ride_id' => $rideId, 'seats' => $bookingData['seats']]);

        return DB::transaction(function () use ($rideId, $bookingData) {
            $ride = Ride::lockForUpdate()->findOrFail($rideId);
            if ($ride->available_seats < $bookingData['seats']) {
                throw new \Exception('Not enough available seats', 400);
            }

            $booking = $ride->bookings()->create($bookingData);
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
