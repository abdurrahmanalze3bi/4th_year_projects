<?php

namespace App\Repositories;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
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
    )
    {
    }

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
            // ────────────────────────────────────────────────────────────
            // 1) Pickup: use 'pickup_location' array if provided, otherwise geocode 'pickup_address'
            // ────────────────────────────────────────────────────────────
            if (
                isset($data['pickup_location'])
                && is_array($data['pickup_location'])
                && array_key_exists('lat', $data['pickup_location'])
                && array_key_exists('lng', $data['pickup_location'])
            ) {
                $pickup = [
                    'lat' => (float) $data['pickup_location']['lat'],
                    'lng' => (float) $data['pickup_location']['lng'],
                ];
                // Reverse‐geocode to get a human‐readable label
                $pickupLabel = $this->routingService->reverseGeocode(
                    $pickup['lat'],
                    $pickup['lng']
                );
            }
            elseif (!empty($data['pickup_address'])) {
                // Controller provided a textual address
                $pickupResult = $this->routingService->geocodeAddress($data['pickup_address']);
                // Expect geocodeAddress to return [ 'lat' => float, 'lng' => float, 'label' => string ]
                $pickup      = [
                    'lat' => (float) $pickupResult['lat'],
                    'lng' => (float) $pickupResult['lng'],
                ];
                $pickupLabel = $pickupResult['label'];
            }
            else {
                throw new \Exception("Missing pickup data: must provide 'pickup_location' or 'pickup_address'.");
            }

            // ────────────────────────────────────────────────────────────
            // 2) Destination: same pattern as pickup
            // ────────────────────────────────────────────────────────────
            if (
                isset($data['destination_location'])
                && is_array($data['destination_location'])
                && array_key_exists('lat', $data['destination_location'])
                && array_key_exists('lng', $data['destination_location'])
            ) {
                $destination = [
                    'lat' => (float) $data['destination_location']['lat'],
                    'lng' => (float) $data['destination_location']['lng'],
                ];
                $destinationLabel = $this->routingService->reverseGeocode(
                    $destination['lat'],
                    $destination['lng']
                );
            }
            elseif (!empty($data['destination_address'])) {
                $destResult       = $this->routingService->geocodeAddress($data['destination_address']);
                $destination      = [
                    'lat' => (float) $destResult['lat'],
                    'lng' => (float) $destResult['lng'],
                ];
                $destinationLabel = $destResult['label'];
            }
            else {
                throw new \Exception("Missing destination data: must provide 'destination_location' or 'destination_address'.");
            }

            // ────────────────────────────────────────────────────────────
            // 3) Route summary: distance, duration, geometry (LineString)
            // ────────────────────────────────────────────────────────────
            $route = $this->routingService->getRouteDetails($pickup, $destination);
            // $route should be something like:
            // [
            //   'distance' => <meters>,
            //   'duration' => <seconds>,
            //   'geometry' => [ [lng1,lat1], [lng2,lat2], … ]   // coordinates array
            // ]

            // ────────────────────────────────────────────────────────────
            // 4) Create the Ride model and fill non‐spatial fields
            // ────────────────────────────────────────────────────────────
            $ride = new Ride();

            $ride->driver_id           = $data['driver_id'];
            $ride->pickup_address      = $pickupLabel;
            $ride->destination_address = $destinationLabel;
            $ride->distance            = $route['distance'];
            $ride->duration            = $route['duration'];
            $ride->departure_time      = Carbon::parse($data['departure_time'])
                ->toDateTimeString();
            $ride->available_seats     = $data['available_seats'];
            $ride->payment_method = $data['payment_method'];
            $ride->price_per_seat      = $data['price_per_seat'];
            $ride->vehicle_type        = $data['vehicle_type'];
            $ride->notes               = $data['notes'] ?? null;
            $ride->booking_type        = $data['booking_type'] ?? 'direct';
            // ────────────────────────────────────────────────────────────
            // 5) Assign spatial columns via Eloquent mutators
            //     (assuming Ride model has setPickupLocationAttribute & setDestinationLocationAttribute)
            // ────────────────────────────────────────────────────────────
            $ride->pickup_location = [
                'lat' => $pickup['lat'],
                'lng' => $pickup['lng'],
            ];
            $ride->destination_location = [
                'lat' => $destination['lat'],
                'lng' => $destination['lng'],
            ];

            // 6) Assign route_geometry as GeoJSON - WITH VALIDATION
            if (isset($route['geometry']) && is_array($route['geometry']) && !empty($route['geometry'])) {
                // Validate that coordinates are properly formatted
                $validCoordinates = true;
                foreach ($route['geometry'] as $coord) {
                    if (!is_array($coord) || count($coord) < 2) {
                        $validCoordinates = false;
                        break;
                    }
                }

                if ($validCoordinates) {
                    $ride->route_geometry = [
                        'type'        => 'LineString',
                        'coordinates' => $route['geometry'],
                    ];
                } else {
                    Log::warning('Invalid route geometry coordinates received', ['geometry' => $route['geometry']]);
                    $ride->route_geometry = null;
                }
            } else {
                Log::warning('No valid geometry data received from routing service');
                $ride->route_geometry = null;
            }

            // ────────────────────────────────────────────────────────────
            // 7) Save and return
            // ────────────────────────────────────────────────────────────
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
     * @param int $rideId
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
     * @param int $rideId
     * @param array $data
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
     * @param int $rideId
     * @return bool
     */
    public function deleteRide(int $rideId): bool
    {
        Log::info('RideRepository: Deleting ride', ['ride_id' => $rideId]);
        return (bool)Ride::destroy($rideId);
    }

    /**
     * Get rides for a specific driver.
     *
     * @param int $userId
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
     * @param int $rideId
     * @param array $bookingData
     * @return Booking
     * @throws \Exception
     */

    public function bookRide(int $rideId, array $bookingData): Booking
    {
        return DB::transaction(function () use ($rideId, $bookingData) {
            $ride = Ride::lockForUpdate()->findOrFail($rideId);

            $booking = Booking::create([
                'user_id' => $bookingData['user_id'],
                'ride_id' => $rideId,
                'seats' => $bookingData['seats'],
                'status' => $bookingData['status'],
                'communication_number' => $bookingData['communication_number'] ?? " ",
            ]);

            if ($booking->status === Booking::CONFIRMED) {
                $ride->decrement('available_seats', $bookingData['seats']);

                // Check if ride is full after booking
                if ($ride->available_seats <= 0) {
                    $ride->status = 'full';
                    $ride->save();
                }
            }

            return $booking->load('user', 'ride');
        });
    }



    /**
     * Search rides based on criteria.
     *
     * @param array $criteria
     * @return Collection
     */
    // RideRepository.php - searchRides method
    public function searchRides(array $params): Collection
    {
        $query = Ride::query()
            ->whereDate('departure_time', '=', Carbon::parse($params['departure_date']))
            ->where('available_seats', '>=', $params['seats_required'])
            ->where('status', 'active'); // Only show active rides (excludes full/cancelled)

        // Add spatial filters
        $this->applySpatialFilters($query, $params);

        return $query->with('driver')->get();
    }

// RideController.php - notifyRideFull method (updated)
    private function notifyRideFull($ride)
    {
        try {
            // Notify passengers who booked this ride
            foreach ($ride->bookings as $booking) {
                $this->notificationService->createNotification(
                    $booking->user,
                    'ride_full',
                    'Ride Full',
                    "The ride from {$ride->pickup_address} to {$ride->destination_address} is now full",
                    [
                        'ride_id' => $ride->id,
                        'pickup_address' => $ride->pickup_address,
                        'destination_address' => $ride->destination_address,
                        'departure_time' => $ride->departure_time->toISOString(),
                    ],
                    'normal',
                    'ride'
                );
            }

            // Notify driver
            $this->notificationService->createNotification(
                $ride->driver,
                'ride_full',
                'Ride Full',
                "Your ride from {$ride->pickup_address} to {$ride->destination_address} is now full",
                [
                    'ride_id' => $ride->id,
                    'pickup_address' => $ride->pickup_address,
                    'destination_address' => $ride->destination_address,
                    'departure_time' => $ride->departure_time->toISOString(),
                ],
                'normal',
                'ride'
            );

            Log::info('Ride marked as full', [
                'ride_id' => $ride->id,
                'driver_id' => $ride->driver_id,
                'bookings_count' => $ride->bookings->count(),
                'total_seats_booked' => $ride->bookings->sum('seats')
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to notify about full ride', [
                'ride_id' => $ride->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    private function applySpatialFilters(EloquentBuilder $query, array $params)
    {
        $maxDistance = 3 * 1000;
        $srcWkt = sprintf('POINT(%F %F)', $params['source_lng'], $params['source_lat']);
        $dstWkt = sprintf('POINT(%F %F)', $params['dest_lng'], $params['dest_lat']);

        $query->where(function ($q) use ($maxDistance, $srcWkt, $dstWkt) {
            // Case A: endpoints within 3 km
            $q->whereRaw(
                "ST_Distance_Sphere(pickup_location, ST_GeomFromText(?, 4326)) <= ?",
                [$srcWkt, $maxDistance]
            )
                ->whereRaw(
                    "ST_Distance_Sphere(destination_location, ST_GeomFromText(?, 4326)) <= ?",
                    [$dstWkt, $maxDistance]
                )

                // Case B: Route passes near both points using existing route_geometry
                // BUT ONLY if route_geometry exists and is valid
                ->orWhere(function ($q2) use ($srcWkt, $dstWkt) {
                    $q2->whereNotNull('route_geometry')
                        ->whereRaw("JSON_VALID(route_geometry)")
                        // Additional check to ensure coordinates array exists
                        ->whereRaw("JSON_EXTRACT(route_geometry, '$.coordinates') IS NOT NULL")
                        ->whereRaw("JSON_TYPE(JSON_EXTRACT(route_geometry, '$.coordinates')) = 'ARRAY'")
                        ->whereRaw(
                            "ST_Contains(
                            ST_Buffer(
                                ST_GeomFromGeoJSON(JSON_UNQUOTE(route_geometry)),
                                0.01
                            ),
                            ST_GeomFromText(?, 4326)
                        )",
                            [$srcWkt]
                        )
                        ->whereRaw(
                            "ST_Contains(
                            ST_Buffer(
                                ST_GeomFromGeoJSON(JSON_UNQUOTE(route_geometry)),
                                0.01
                            ),
                            ST_GeomFromText(?, 4326)
                        )",
                            [$dstWkt]
                        );
                });
        });
    }
    // app/Repositories/RideRepository.php
    public function cancelRide(int $rideId, int $driverId): Ride
    {
        return DB::transaction(function () use ($rideId, $driverId) {
            $ride = Ride::where('driver_id', $driverId)
                ->findOrFail($rideId);
            if ($ride->status === 'cancelled') {
                throw new \Exception('Ride is already cancelled');
            }

            $ride->status = 'cancelled';
            $ride->save();

            Log::info('Ride cancelled', ['ride_id' => $rideId, 'driver_id' => $driverId]);
            return $ride->fresh();
        });
    }
    /**
     * Create a ride with pre-calculated geometry
     */
    public function createRideWithGeometry(array $data): Ride
    {
        return DB::transaction(function () use ($data) {
            $ride = new Ride();

            $ride->driver_id = $data['driver_id'];
            $ride->pickup_address = $data['pickup_address'];
            $ride->destination_address = $data['destination_address'];
            $ride->distance = $data['distance'];
            $ride->duration = $data['duration'];
            $ride->route_geometry = $data['route_geometry'];
            $ride->chosen_route_index = $data['chosen_route_index']; // NEW: Save index
            $ride->departure_time = Carbon::parse($data['departure_time']);
            $ride->available_seats = $data['available_seats'];
            $ride->price_per_seat = $data['price_per_seat'];
            $ride->payment_method = $data['payment_method'];
            $ride->vehicle_type = $data['vehicle_type'];
            $ride->notes = $data['notes'] ?? null;
            $ride->booking_type = $data['booking_type'] ?? 'direct';
            $ride->communication_number = $data['communication_number'] ?? null;
            $ride->pickup_location = [
                'lat' => $data['pickup_location']['lat'],
                'lng' => $data['pickup_location']['lng'],
            ];

            $ride->destination_location = [
                'lat' => $data['destination_location']['lat'],
                'lng' => $data['destination_location']['lng'],
            ];

            $ride->save();
            return $ride->fresh();
        });
    }
}
