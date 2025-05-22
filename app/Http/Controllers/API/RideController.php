<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Interfaces\RideRepositoryInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class RideController extends Controller
{
    private $rideRepository;

    public function __construct(RideRepositoryInterface $rideRepository)
    {
        $this->rideRepository = $rideRepository;
    }

    public function createRide(Request $request)
    {
        $user = $request->user();

        // Only verified drivers can create rides
        if (! $user->is_verified_driver) {
            return response()->json([
                'success' => false,
                'message' => 'You must be verified as a driver to create rides.'
            ], 403);
        }

        // Validate ride input
        $validator = Validator::make($request->all(), [
            'pickup_address'      => 'required|string|max:255',
            'destination_address' => 'required|string|max:255',
            'departure_time'      => 'required|date|after:now',
            'available_seats'     => 'required|integer|min:1',
            'price_per_seat'      => 'required|numeric|min:0',
            'vehicle_type'        => 'required|string|max:50',
            'notes'               => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors'  => $validator->errors(),
            ], 422);
        }

        try {
            $rideData = array_merge($validator->validated(), ['driver_id' => $user->id]);
            $ride = $this->rideRepository->createRide($rideData);

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

    /**
     * List upcoming rides
     * GET /api/rides
     */
    public function getRides()
    {
        try {
            $rides = $this->rideRepository->getUpcomingRides();

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
     * Get detailed ride info
     * GET /api/rides/{rideId}
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
     * Book a ride (passengers only)
     * POST /api/rides/{rideId}/book
     */
    public function bookRide(Request $request, int $rideId)
    {
        $user = $request->user();

        // Only verified passengers can book rides
        if (! $user->is_verified_passenger) {
            return response()->json([
                'success' => false,
                'message' => 'You must be verified as a passenger to book rides.'
            ], 403);
        }

        // Validate booking input
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

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
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
                'name'   => trim($ride->driver->first_name . ' ' . $ride->driver->last_name),
                'avatar' => $ride->driver->avatar, // or profile_photo_url if you’ve saved it in users.avatar
            ],
// …
            'pickup' => [
                'address'     => $ride->pickup_address,
                'coordinates' => $ride->pickup_location ? [
                    'lat' => $ride->pickup_location['lat'],
                    'lng' => $ride->pickup_location['lng'],
                ] : null,
            ],
            'destination' => [
                'address'     => $ride->destination_address,
                'coordinates' => $ride->destination_location ? [
                    'lat' => $ride->destination_location['lat'],
                    'lng' => $ride->destination_location['lng'],
                ] : null,
            ],

            'departure' => $ride->departure_time->toIso8601String(),
            'seats_available' => $ride->available_seats,
            'price_per_seat' => $ride->price_per_seat,
            'distance' => [
                'meters' => $ride->distance,
                'kilometers' => round($ride->distance / 1000, 1)
            ],
            'duration' => [
                'seconds' => $ride->duration,
                'minutes' => round($ride->duration / 60)
            ],
            'vehicle_type' => $ride->vehicle_type
        ];
    }

    private function formatRideDetailsResponse($ride)
    {
        return array_merge($this->formatRideResponse($ride), [
            'route_geometry' => $ride->route_geometry,
            'bookings' => $ride->bookings->map(fn ($booking) => [
                'user' => [
                    'id' => $booking->user->id,
                    'name' => $booking->user->full_name
                ],
                'seats' => $booking->seats,
                'booked_at' => $booking->created_at->toIso8601String()
            ])
        ]);
    }

    private function formatBookingResponse($booking)
    {
        return [
            'id' => $booking->id,
            'ride_id' => $booking->ride_id,
            'seats' => $booking->seats,
            'status' => 'confirmed',
            'total_price' => $booking->seats * $booking->ride->price_per_seat,
            'booking_date' => $booking->created_at->toIso8601String()
        ];
    }

    private function validationErrorResponse($validator)
    {
        return response()->json([
            'success' => false,
            'errors' => $validator->errors()
        ], 422);
    }

    private function errorResponse(string $message, int $statusCode)
    {
        // Ensure the message is valid UTF-8
        $message = mb_convert_encoding($message, 'UTF-8', 'UTF-8');

        // Use JSON_PARTIAL_OUTPUT_ON_ERROR so Laravel will skip over anything it can't encode
        return response()->json(
            ['success' => false, 'message' => $message],
            $statusCode,
            [],
            JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR
        );
    }

}
