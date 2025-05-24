<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Interfaces\GeocodingServiceInterface;
use App\Interfaces\RideRepositoryInterface;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class RideController extends Controller
{
    private RideRepositoryInterface      $rideRepository;
    private GeocodingServiceInterface    $geo;

    public function __construct(
        RideRepositoryInterface $rideRepository,
        GeocodingServiceInterface $geocodingService
    ) {
        $this->rideRepository = $rideRepository;
        $this->geo            = $geocodingService;
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

                // Use the new spatial-array keys instead of separate lat/lng fields
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

            // Delegate creation to repository
            $ride = $this->rideRepository->createRide($data);

            return response()->json([
                'success' => true,
                'data'    => $this->formatRideResponse($ride),
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ride creation failed: ' . $e->getMessage(),
            ], 400);
        }
    }

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

    public function getRides(Request $request)
    {
        $user = $request->user();    // the authenticated user

        try {
            // only rides for this driver
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

        // Add this check to prevent self-booking
        $ride = $this->rideRepository->getRideById($rideId);
        if ($ride->driver_id === $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Drivers cannot book their own rides'
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
            $booking = $this->rideRepository->bookRide($rideId, [
                'user_id' => $user->id,
                'seats'   => $request->input('seats'),
            ]);

            return response()->json([
                'success' => true,
                'data'    => $this->formatBookingResponse($booking),
            ], 201);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }
    }

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

    private function formatRideResponse($ride)
    {
        return [
            'id' => $ride->id,
            'driver' => [
                'id'     => $ride->driver->id,
                'name'   => trim($ride->driver->first_name . ' ' . $ride->driver->last_name),
                'avatar' => $ride->driver->avatar,
            ],
            // Use new POINT accessor instead of separate lat/lng
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
        ];
    }

    private function formatRideDetailsResponse($ride)
    {
        return array_merge($this->formatRideResponse($ride), [
            'route_geometry' => $ride->route_geometry,
            'bookings' => $ride->bookings->map(fn ($booking) => [
                'user' => [
                    'id'   => $booking->user->id,
                    'name' => $booking->user->full_name,
                ],
                'seats'     => $booking->seats,
                'booked_at' => $booking->created_at->toIso8601String(),
            ]),
        ]);
    }

    private function formatBookingResponse($booking)
    {
        return [
            'id' => $booking->id,
            'ride_id' => $booking->ride_id,
            'driver_id' => $booking->ride->driver_id, // Add driver ID
            'passenger_id' => $booking->user_id,      // Add passenger ID
            'seats' => $booking->seats,
            'status' => 'confirmed',
            'total_price' => $booking->seats * $booking->ride->price_per_seat,
            'booking_date' => $booking->created_at->toIso8601String(),
        ];
    }

    private function validationErrorResponse($validator)
    {
        return response()->json([
            'success' => false,
            'errors'  => $validator->errors(),
        ], 422);
    }

    public function searchRides(Request $request)
    {
        $validator = Validator::make($request->all(), [
            // require either source_address or BOTH source_lat & source_lng
            'source_address'      => 'required_without_all:source_lat,source_lng|string|max:255',
            'source_lat'          => 'required_with:source_lng|numeric',
            'source_lng'          => 'required_with:source_lat|numeric',

            // same for destination
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

            // Now call the repository with unified lat/lng keys
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
            return response()->json([
                'success' => false,
                'message' => 'Search failed: ' . $e->getMessage(),
            ], 500);
        }
    }
    // app/Http/Controllers/API/RideController.php
    public function cancelRide(Request $request, int $rideId)
    {
        $user = $request->user();

        try {
            $ride = $this->rideRepository->cancelRide($rideId, $user->id);

            return response()->json([
                'success' => true,
                'data' => $this->formatRideResponse($ride),
                'message' => 'Ride cancelled successfully'
            ], 200);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ride not found or you are not the driver'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }
    }

}
