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

        // Validate driver profile completeness
        $this->validateDriverProfile($user);

        // Validate ride input
        $validator = Validator::make($request->all(), [
            'pickup_address' => 'required|string|max:255',
            'destination_address' => 'required|string|max:255',
            'departure_time' => 'required|date|after:now',
            'available_seats' => 'required|integer|min:1',
            'price_per_seat' => 'required|numeric|min:0',
            'vehicle_type' => 'required|string|max:50',
            'notes' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        try {
            $rideData = array_merge($validator->validated(), ['driver_id' => $user->id]);
            $ride = $this->rideRepository->createRide($rideData);

            return response()->json([
                'success' => true,
                'data' => $this->formatRideResponse($ride)
            ], 201);

        } catch (\Exception $e) {
            return $this->errorResponse('Ride creation failed: ' . $e->getMessage(), 400);
        }
    }

    public function getRides(Request $request)
    {
        try {
            $rides = $this->rideRepository->getUpcomingRides();

            return response()->json([
                'success' => true,
                'data' => $rides->map(fn ($ride) => $this->formatRideResponse($ride))
            ]);

        } catch (\Exception $e) {
            return $this->errorResponse('Failed to fetch rides: ' . $e->getMessage(), 500);
        }
    }

    public function getRideDetails($rideId)
    {
        try {
            $ride = $this->rideRepository->getRideById($rideId);
            return response()->json([
                'success' => true,
                'data' => $this->formatRideDetailsResponse($ride)
            ]);

        } catch (\Exception $e) {
            return $this->errorResponse('Ride not found: ' . $e->getMessage(), 404);
        }
    }

    public function bookRide(Request $request, $rideId)
    {
        $validator = Validator::make($request->all(), [
            'seats' => 'required|integer|min:1|max:10'
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        try {
            $booking = $this->rideRepository->bookRide($rideId, [
                'user_id' => $request->user()->id,
                'seats' => $request->seats
            ]);

            return response()->json([
                'success' => true,
                'data' => $this->formatBookingResponse($booking)
            ], 201);

        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 400);
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
                'name' => $ride->driver->full_name,
                'avatar' => $ride->driver->profile->profile_photo_url
            ],
            'pickup' => [
                'address' => $ride->pickup_address,
                'coordinates' => [
                    'lat' => $ride->pickup_lat,
                    'lng' => $ride->pickup_lng
                ]
            ],
            'destination' => [
                'address' => $ride->destination_address,
                'coordinates' => [
                    'lat' => $ride->destination_lat,
                    'lng' => $ride->destination_lng
                ]
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

    private function errorResponse($message, $statusCode)
    {
        return response()->json([
            'success' => false,
            'message' => $message
        ], $statusCode);
    }
}
