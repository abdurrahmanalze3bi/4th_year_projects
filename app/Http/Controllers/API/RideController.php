<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Interfaces\GeocodingServiceInterface;
use App\Interfaces\RideRepositoryInterface;
use App\Services\NotificationService;
use App\Events\RideBooked;
use App\Events\RideCancelled;
use App\Events\RideCreated;
use App\Models\User;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class RideController extends Controller
{
    private RideRepositoryInterface      $rideRepository;
    private GeocodingServiceInterface    $geo;
    private NotificationService          $notificationService;

    public function __construct(
        RideRepositoryInterface $rideRepository,
        GeocodingServiceInterface $geocodingService,
        NotificationService $notificationService
    ) {
        $this->rideRepository = $rideRepository;
        $this->geo            = $geocodingService;
        $this->notificationService = $notificationService;
    }

    /**
     * Create a ride (drivers only). Accepts either raw address or exact coords.
     */
    public function createRide(Request $request)
    {
        $user = $request->user();

        // Only verified drivers
        if (! $user->is_verified_driver) {
            return response()->json([
                'success' => false,
                'message' => 'You must be verified as a driver to create rides.'
            ], 403);
        }

        // Validate driver profile
        $this->validateDriverProfile($user);

        // Validate input: require either address or coordinates for each end
        $validator = Validator::make($request->all(), [
            'pickup_address'       => 'required_without:pickup_lat|string|max:255',
            'pickup_lat'           => 'required_without:pickup_address|numeric',
            'pickup_lng'           => 'required_with:pickup_lat|numeric',
            'destination_address'  => 'required_without:destination_lat|string|max:255',
            'destination_lat'      => 'required_without:destination_address|numeric',
            'destination_lng'      => 'required_with:destination_lat|numeric',
            'departure_time'       => 'required|date|after:now',
            'available_seats'      => 'required|integer|min:1',
            'price_per_seat'       => 'required|numeric|min:0',
            'vehicle_type'         => 'required|string|max:50',
            'notes'                => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors'  => $validator->errors(),
            ], 422);
        }

        try {
            DB::beginTransaction();

            // Determine pickup data
            if ($request->filled('pickup_address')) {
                $pickup = $this->geo->geocodeAddress($request->input('pickup_address'));
            } else {
                $pickup = [
                    'lat'   => (float)$request->input('pickup_lat'),
                    'lng'   => (float)$request->input('pickup_lng'),
                    'label' => null,
                ];
            }

            // Determine destination data
            if ($request->filled('destination_address')) {
                $destination = $this->geo->geocodeAddress($request->input('destination_address'));
            } else {
                $destination = [
                    'lat'   => (float)$request->input('destination_lat'),
                    'lng'   => (float)$request->input('destination_lng'),
                    'label' => null,
                ];
            }

            // Assemble payload for repository
            $data = [
                'driver_id'           => $user->id,
                'pickup_address'      => $request->input('pickup_address') ?? $pickup['label'],
                'destination_address' => $request->input('destination_address') ?? $destination['label'],
                'pickup_location'     => [
                    'lat' => $pickup['lat'],
                    'lng' => $pickup['lng'],
                ],
                'destination_location'=> [
                    'lat' => $destination['lat'],
                    'lng' => $destination['lng'],
                ],
                'departure_time'      => $request->input('departure_time'),
                'available_seats'     => $request->input('available_seats'),
                'price_per_seat'      => $request->input('price_per_seat'),
                'vehicle_type'        => $request->input('vehicle_type'),
                'notes'               => $request->input('notes'),
            ];

            // Create the ride
            $ride = $this->rideRepository->createRide($data);

            // Send notifications to nearby passengers
            $this->notifyNearbyPassengers($ride, $pickup, $destination);

            // Send confirmation notification to driver
            $this->notificationService->createNotification(
                $user,
                'ride_created',
                'Ride Created Successfully',
                "Your ride from {$ride->pickup_address} to {$ride->destination_address} has been created and is now available for booking.",
                [
                    'ride_id' => $ride->id,
                    'pickup_address' => $ride->pickup_address,
                    'destination_address' => $ride->destination_address,
                    'departure_time' => $ride->departure_time->toISOString(),
                ],
                'normal',
                'ride'
            );

            // Broadcast ride created event
            broadcast(new RideCreated($ride));

            DB::commit();

            Log::info('Ride created successfully', [
                'ride_id' => $ride->id,
                'driver_id' => $user->id,
                'pickup' => $ride->pickup_address,
                'destination' => $ride->destination_address
            ]);

            return response()->json([
                'success' => true,
                'data'    => $this->formatRideResponse($ride),
                'message' => 'Ride created successfully and nearby passengers have been notified.'
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Ride creation failed', [
                'driver_id' => $user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ride creation failed: ' . $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Book a ride (passengers only)
     */
    public function bookRide(Request $request, int $rideId)
    {
        $user = $request->user();

        if (!$user->is_verified_passenger) {
            return response()->json([
                'success' => false,
                'message' => 'You must be verified as a passenger to book rides.'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'seats' => 'required|integer|min:1|max:10',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors'  => $validator->errors(),
            ], 422);
        }

        try {
            DB::beginTransaction();

            // Get ride details first
            $ride = $this->rideRepository->getRideById($rideId);

            // Prevent self-booking
            if ($ride->driver_id === $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Drivers cannot book their own rides'
                ], 403);
            }

            // Check if user already booked this ride
            $existingBooking = $ride->bookings()->where('user_id', $user->id)->first();
            if ($existingBooking) {
                return response()->json([
                    'success' => false,
                    'message' => 'You have already booked this ride'
                ], 400);
            }

            // Create the booking
            $booking = $this->rideRepository->bookRide($rideId, [
                'user_id' => $user->id,
                'seats'   => $request->input('seats'),
            ]);

            // Send notification to driver
            $this->notificationService->createNotification(
                $ride->driver,
                'ride_booked',
                'New Ride Booking',
                "{$user->first_name} {$user->last_name} has booked {$booking->seats} seat(s) for your ride from {$ride->pickup_address} to {$ride->destination_address}.",
                [
                    'ride_id' => $ride->id,
                    'booking_id' => $booking->id,
                    'passenger_id' => $user->id,
                    'passenger_name' => "{$user->first_name} {$user->last_name}",
                    'seats_booked' => $booking->seats,
                    'total_price' => $booking->seats * $ride->price_per_seat,
                    'pickup_address' => $ride->pickup_address,
                    'destination_address' => $ride->destination_address,
                    'departure_time' => $ride->departure_time->toISOString(),
                ],
                'high',
                'ride'
            );

            // Send confirmation notification to passenger
            $this->notificationService->createNotification(
                $user,
                'booking_confirmed',
                'Booking Confirmed',
                "Your booking for {$booking->seats} seat(s) on the ride from {$ride->pickup_address} to {$ride->destination_address} is confirmed. Total cost: $" . ($booking->seats * $ride->price_per_seat),
                [
                    'ride_id' => $ride->id,
                    'booking_id' => $booking->id,
                    'driver_id' => $ride->driver_id,
                    'driver_name' => "{$ride->driver->first_name} {$ride->driver->last_name}",
                    'seats_booked' => $booking->seats,
                    'total_price' => $booking->seats * $ride->price_per_seat,
                    'pickup_address' => $ride->pickup_address,
                    'destination_address' => $ride->destination_address,
                    'departure_time' => $ride->departure_time->toISOString(),
                ],
                'normal',
                'ride'
            );

            // Broadcast ride booked event
            broadcast(new RideBooked($ride, $booking, $user));

            // Notify other interested passengers if ride is now full
            $remainingSeats = $ride->available_seats - $ride->bookings()->sum('seats');
            if ($remainingSeats <= 0) {
                $this->notifyRideFull($ride);
            }

            DB::commit();

            Log::info('Ride booked successfully', [
                'ride_id' => $ride->id,
                'booking_id' => $booking->id,
                'passenger_id' => $user->id,
                'driver_id' => $ride->driver_id,
                'seats' => $booking->seats
            ]);

            return response()->json([
                'success' => true,
                'data'    => $this->formatBookingResponse($booking),
                'message' => 'Ride booked successfully! The driver has been notified.'
            ], 201);

        } catch (Exception $e) {
            DB::rollBack();

            Log::error('Ride booking failed', [
                'ride_id' => $rideId,
                'passenger_id' => $user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Cancel a ride
     */
    public function cancelRide(Request $request, int $rideId)
    {
        $user = $request->user();

        try {
            DB::beginTransaction();

            $ride = $this->rideRepository->getRideById($rideId);

            // Check if user is the driver
            if ($ride->driver_id !== $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only the ride driver can cancel the ride'
                ], 403);
            }

            // Get all bookings before cancellation
            $bookings = $ride->bookings()->with('user')->get();

            // Cancel the ride
            $cancelledRide = $this->rideRepository->cancelRide($rideId, $user->id);

            // Notify all passengers about cancellation
            foreach ($bookings as $booking) {
                $this->notificationService->createNotification(
                    $booking->user,
                    'ride_cancelled',
                    'Ride Cancelled',
                    "Unfortunately, the ride from {$ride->pickup_address} to {$ride->destination_address} scheduled for {$ride->departure_time->format('M j, Y \a\t g:i A')} has been cancelled by the driver. You will receive a full refund.",
                    [
                        'ride_id' => $ride->id,
                        'booking_id' => $booking->id,
                        'driver_id' => $ride->driver_id,
                        'driver_name' => "{$ride->driver->first_name} {$ride->driver->last_name}",
                        'refund_amount' => $booking->seats * $ride->price_per_seat,
                        'pickup_address' => $ride->pickup_address,
                        'destination_address' => $ride->destination_address,
                        'original_departure_time' => $ride->departure_time->toISOString(),
                        'cancellation_time' => now()->toISOString(),
                    ],
                    'high',
                    'ride'
                );
            }

            // Send confirmation to driver
            $this->notificationService->createNotification(
                $user,
                'ride_cancelled_confirmation',
                'Ride Cancelled',
                "Your ride from {$ride->pickup_address} to {$ride->destination_address} has been cancelled successfully. All passengers have been notified and will receive refunds.",
                [
                    'ride_id' => $ride->id,
                    'passengers_count' => $bookings->count(),
                    'total_bookings' => $bookings->sum('seats'),
                    'pickup_address' => $ride->pickup_address,
                    'destination_address' => $ride->destination_address,
                    'cancellation_time' => now()->toISOString(),
                ],
                'normal',
                'ride'
            );

            // Broadcast ride cancelled event
            broadcast(new RideCancelled($ride, $bookings->toArray(), $user));

            DB::commit();

            Log::info('Ride cancelled successfully', [
                'ride_id' => $ride->id,
                'driver_id' => $user->id,
                'passengers_notified' => $bookings->count(),
                'total_seats_cancelled' => $bookings->sum('seats')
            ]);

            return response()->json([
                'success' => true,
                'data' => $this->formatRideResponse($cancelledRide),
                'message' => "Ride cancelled successfully. {$bookings->count()} passenger(s) have been notified."
            ], 200);

        } catch (ModelNotFoundException $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Ride not found or you are not the driver'
            ], 404);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Ride cancellation failed', [
                'ride_id' => $rideId,
                'driver_id' => $user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Get ride details
     */
    public function getRideDetails(int $rideId)
    {
        try {
            $ride = $this->rideRepository->getRideById($rideId);

            return response()->json([
                'success' => true,
                'data'    => $this->formatRideDetailsResponse($ride),
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ride not found: ' . $e->getMessage(),
            ], 404);
        }
    }

    /**
     * Get user's rides (driver's rides)
     */
    public function getRides(Request $request)
    {
        $user = $request->user();

        try {
            $rides = $this->rideRepository->getDriverRides($user->id);

            return response()->json([
                'success' => true,
                'data'    => $rides->map(fn($ride) => $this->formatRideResponse($ride)),
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch rides: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Search for available rides
     */
    public function searchRides(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'source_address'      => 'required_without_all:source_lat,source_lng|string|max:255',
            'source_lat'          => 'required_with:source_lng|numeric',
            'source_lng'          => 'required_with:source_lat|numeric',
            'destination_address' => 'required_without_all:dest_lat,dest_lng|string|max:255',
            'dest_lat'            => 'required_with:dest_lng|numeric',
            'dest_lng'            => 'required_with:dest_lat|numeric',
            'departure_date'      => 'required|date|after:yesterday',
            'seats_required'      => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors'  => $validator->errors(),
            ], 422);
        }

        try {
            // SOURCE: either geocode the address, or use the raw coords
            if ($request->filled('source_address')) {
                $passengerSource = $this->geo->geocodeAddress($request->source_address);
            } else {
                $passengerSource = [
                    'lat'   => (float)$request->source_lat,
                    'lng'   => (float)$request->source_lng,
                    'label' => null,
                ];
            }

            // DESTINATION: same pattern
            if ($request->filled('destination_address')) {
                $passengerDest = $this->geo->geocodeAddress($request->destination_address);
            } else {
                $passengerDest = [
                    'lat'   => (float)$request->dest_lat,
                    'lng'   => (float)$request->dest_lng,
                    'label' => null,
                ];
            }

            $rides = $this->rideRepository->searchRides([
                'departure_date' => $request->departure_date,
                'seats_required' => $request->seats_required,
                'source_lat'     => $passengerSource['lat'],
                'source_lng'     => $passengerSource['lng'],
                'dest_lat'       => $passengerDest['lat'],
                'dest_lng'       => $passengerDest['lng'],
            ]);

            return response()->json([
                'success' => true,
                'data'    => $rides->map(fn($ride) => $this->formatRideResponse($ride)),
            ], 200);

        } catch (\Exception $e) {
            Log::error('Ride search failed', [
                'error' => $e->getMessage(),
                'search_params' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Search failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Address autocomplete
     */
    public function autocomplete(Request $request)
    {
        $text = $request->query('text', '');
        if (strlen(trim($text)) < 2) {
            return response()->json(['success' => false, 'message' => 'Type at least 2 characters'], 422);
        }

        try {
            $results = $this->geo->autocomplete($text);
            return response()->json(['success' => true, 'data' => $results]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // ====== PRIVATE HELPER METHODS ======

    /**
     * Notify nearby passengers about new ride
     */
    private function notifyNearbyPassengers($ride, $pickup, $destination)
    {
        try {
            // Get passengers who might be interested (within reasonable distance)
            $nearbyPassengers = User::where('is_verified_passenger', true)
                ->where('id', '!=', $ride->driver_id)
                ->whereHas('profile', function($query) use ($pickup, $destination) {
                    // This would need to be implemented based on your user location tracking
                    // For now, we'll send to all verified passengers
                })
                ->get();

            foreach ($nearbyPassengers as $passenger) {
                $this->notificationService->createNotification(
                    $passenger,
                    'new_ride_available',
                    'New Ride Available',
                    "A new ride from {$ride->pickup_address} to {$ride->destination_address} is now available for booking. Departure: {$ride->departure_time->format('M j, Y \a\t g:i A')}",
                    [
                        'ride_id' => $ride->id,
                        'driver_id' => $ride->driver_id,
                        'driver_name' => "{$ride->driver->first_name} {$ride->driver->last_name}",
                        'pickup_address' => $ride->pickup_address,
                        'destination_address' => $ride->destination_address,
                        'departure_time' => $ride->departure_time->toISOString(),
                        'available_seats' => $ride->available_seats,
                        'price_per_seat' => $ride->price_per_seat,
                        'vehicle_type' => $ride->vehicle_type,
                    ],
                    'normal',
                    'ride'
                );
            }

            Log::info('Notified nearby passengers about new ride', [
                'ride_id' => $ride->id,
                'passengers_notified' => $nearbyPassengers->count()
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to notify nearby passengers', [
                'ride_id' => $ride->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Notify passengers when ride becomes full
     */
    private function notifyRideFull($ride)
    {
        try {
            // This could notify users who were interested but didn't book yet
            // Implementation depends on your wishlist/interest tracking system
            Log::info('Ride is now full', ['ride_id' => $ride->id]);
        } catch (\Exception $e) {
            Log::error('Failed to notify about full ride', [
                'ride_id' => $ride->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Validate driver profile completeness
     */
    private function validateDriverProfile($user)
    {
        $requiredFields = [
            'type_of_car', 'color_of_car', 'number_of_seats',
            'car_pic', 'face_id_pic', 'back_id_pic',
            'driving_license_pic', 'mechanic_card_pic'
        ];

        foreach ($requiredFields as $field) {
            if (!$user->profile || empty($user->profile->$field)) {
                abort(response()->json([
                    'success' => false,
                    'message' => 'Complete your driver profile first'
                ], 403));
            }
        }
    }

    /**
     * Format ride response
     */
    private function formatRideResponse($ride)
    {
        return [
            'id' => $ride->id,
            'driver' => [
                'id'     => $ride->driver->id,
                'name'   => trim($ride->driver->first_name . ' ' . $ride->driver->last_name),
                'avatar' => $ride->driver->avatar,
                'rating' => $ride->driver->driver_rating ?? 0,
            ],
            'pickup' => [
                'address'     => $ride->pickup_address,
                'coordinates' => $ride->pickup_location
                    ? [
                        'lat' => $ride->pickup_location['lat'],
                        'lng' => $ride->pickup_location['lng'],
                    ]
                    : null,
            ],
            'destination' => [
                'address'     => $ride->destination_address,
                'coordinates' => $ride->destination_location
                    ? [
                        'lat' => $ride->destination_location['lat'],
                        'lng' => $ride->destination_location['lng'],
                    ]
                    : null,
            ],
            'departure' => $ride->departure_time->toIso8601String(),
            'seats_available' => $ride->available_seats,
            'seats_booked' => $ride->bookings()->sum('seats') ?? 0,
            'price_per_seat'  => $ride->price_per_seat,
            'status' => $ride->status,
            'distance' => [
                'meters'     => $ride->distance,
                'kilometers' => round($ride->distance / 1000, 1),
            ],
            'duration' => [
                'seconds' => $ride->duration,
                'minutes' => round($ride->duration / 60),
            ],
            'vehicle_type' => $ride->vehicle_type,
            'notes' => $ride->notes,
            'created_at' => $ride->created_at->toIso8601String(),
        ];
    }

    /**
     * Format ride details response
     */
    private function formatRideDetailsResponse($ride)
    {
        return array_merge($this->formatRideResponse($ride), [
            'route_geometry' => $ride->route_geometry,
            'bookings' => $ride->bookings->map(fn ($booking) => [
                'id' => $booking->id,
                'user' => [
                    'id'   => $booking->user->id,
                    'name' => $booking->user->first_name . ' ' . $booking->user->last_name,
                    'avatar' => $booking->user->avatar,
                    'rating' => $booking->user->passenger_rating ?? 0,
                ],
                'seats'     => $booking->seats,
                'status'    => $booking->status ?? 'confirmed',
                'booked_at' => $booking->created_at->toIso8601String(),
                'total_price' => $booking->seats * $ride->price_per_seat,
            ]),
        ]);
    }

    /**
     * Format booking response
     */
    private function formatBookingResponse($booking)
    {
        return [
            'id' => $booking->id,
            'ride_id' => $booking->ride_id,
            'driver_id' => $booking->ride->driver_id,
            'passenger_id' => $booking->user_id,
            'seats' => $booking->seats,
            'status' => $booking->status ?? 'confirmed',
            'total_price' => $booking->seats * $booking->ride->price_per_seat,
            'booking_date' => $booking->created_at->toIso8601String(),
            'ride_details' => [
                'pickup_address' => $booking->ride->pickup_address,
                'destination_address' => $booking->ride->destination_address,
                'departure_time' => $booking->ride->departure_time->toIso8601String(),
            ]
        ];
    }
}
