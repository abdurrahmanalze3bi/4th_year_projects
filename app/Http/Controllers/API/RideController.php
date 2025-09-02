<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Interfaces\GeocodingServiceInterface;
use App\Interfaces\RideRepositoryInterface;
use App\Models\Booking;
use App\Models\Ride;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\NotificationService;
use App\Events\RideBooked;
use App\Events\RideCancelled;
use App\Events\RideCreated;
use App\Models\User;
use Carbon\Carbon;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
        if (!$user->is_verified_driver) {
            return response()->json([
                'success' => false,
                'message' => 'You must be verified as a driver to create rides.'
            ], 403);
        }

        $this->validateDriverProfile($user);

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
            'booking_type' => 'required|in:direct,request',
            'payment_method'       => 'required|in:cash,e-pay', // Added payment method validation
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

            // Calculate required fee: 5% of (4 * total ride price)
            $totalRidePrice = $request->price_per_seat * $request->available_seats;
            $requiredFee = ( $totalRidePrice) * 0.05;

            // Get driver's wallet with lock
            $driverWallet = Wallet::where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if (!$driverWallet) {
                throw new \Exception('Wallet not found for this driver');
            }

            // Verify sufficient balance
            if ($driverWallet->balance < $requiredFee) {
                throw new \Exception(
                    'Insufficient wallet balance. Required fee: ' . number_format($requiredFee, 2) .
                    '. Current balance: ' . number_format($driverWallet->balance, 2)
                );
            }

            // Get SyCash wallet
            $syCashConfig = AdminDashboardController::ADMIN_CONFIGS['sycash'];
            $syCashWallet = Wallet::where('phone_number', $syCashConfig['phone'])
                ->lockForUpdate()
                ->first();

            if (!$syCashWallet) {
                throw new \Exception('SyCash system wallet not found');
            }

            // Transfer funds
            $driverPreviousBalance = $driverWallet->balance;
            $driverWallet->balance -= $requiredFee;
            $driverWallet->save();

            $syCashPreviousBalance = $syCashWallet->balance;
            $syCashWallet->balance += $requiredFee;
            $syCashWallet->save();

            // Record transactions
            $transactionId = 'RIDE_FEE_' . time() . '_' . Str::random(6);

            // Driver transaction
            WalletTransaction::create([
                'wallet_id' => $driverWallet->id,
                'user_id' => $user->id,
                'type' => 'ride_creation_fee',
                'amount' => -$requiredFee,
                'previous_balance' => $driverPreviousBalance,
                'new_balance' => $driverWallet->balance,
                'description' => 'Ride creation fee deduction',
                'transaction_id' => $transactionId,
                'status' => 'completed',
                'metadata' => [
                    'ride_type' => 'standard',
                    'total_ride_price' => $totalRidePrice,
                    'calculation' => '5% of (4 x ' . $totalRidePrice . ')',
                    'payment_method' => $request->payment_method // Track payment method
                ]
            ]);

            // SyCash transaction
            WalletTransaction::create([
                'wallet_id' => $syCashWallet->id,
                'user_id' => $syCashWallet->user_id,
                'type' => 'ride_creation_fee',
                'amount' => $requiredFee,
                'previous_balance' => $syCashPreviousBalance,
                'new_balance' => $syCashWallet->balance,
                'description' => 'Ride creation fee from driver: ' . $user->email,
                'transaction_id' => 'SYCA_' . $transactionId,
                'status' => 'completed',
                'metadata' => [
                    'driver_id' => $user->id,
                    'driver_name' => $user->first_name . ' ' . $user->last_name,
                    'fee_calculation' => '5% of (4 x ' . $totalRidePrice . ')',
                    'payment_method' => $request->payment_method // Track payment method
                ]
            ]);

            // Continue with ride creation
            if ($request->filled('pickup_address')) {
                $pickup = $this->geo->geocodeAddress($request->input('pickup_address'));
            } else {
                $pickup = [
                    'lat'   => (float)$request->input('pickup_lat'),
                    'lng'   => (float)$request->input('pickup_lng'),
                    'label' => null,
                ];
            }

            if ($request->filled('destination_address')) {
                $destination = $this->geo->geocodeAddress($request->input('destination_address'));
            } else {
                $destination = [
                    'lat'   => (float)$request->input('destination_lat'),
                    'lng'   => (float)$request->input('destination_lng'),
                    'label' => null,
                ];
            }

            $data = [
                'driver_id'           => $user->id,
                'pickup_address'      => $request->input('pickup_address') ?? $pickup['label'],
                'destination_address' => $request->input('destination_address') ?? $destination['label'],
                'pickup_location'     => [
                    'lat' => $pickup['lat'],
                    'lng' => $pickup['lng'],
                ],
                'destination_location' => [
                    'lat' => $destination['lat'],
                    'lng' => $destination['lng'],
                ],
                'departure_time'      => $request->input('departure_time'),
                'available_seats'     => $request->input('available_seats'),
                'price_per_seat'      => $request->input('price_per_seat'),
                'vehicle_type'        => $user->profile->type_of_car,
                'booking_type' => $request->input('booking_type'),
                'payment_method'      => $request->input('payment_method'), // Added payment method
                'notes'               => $request->input('notes'),
            ];

            $ride = $this->rideRepository->createRide($data);

            $driverProfile = $user->profile;
            $driverProfile->number_of_rides = $driverProfile->number_of_rides + 1;
            $driverProfile->save();

            $this->notifyNearbyPassengers($ride, $pickup, $destination);

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
                    'payment_method' => $ride->payment_method // Include payment method
                ],
                'normal',
                'ride'
            );

            broadcast(new RideCreated($ride));

            DB::commit();

            Log::info('Ride created successfully', [
                'ride_id' => $ride->id,
                'driver_id' => $user->id,
                'pickup' => $ride->pickup_address,
                'destination' => $ride->destination_address,
                'new_ride_count' => $driverProfile->number_of_rides,
                'vehicle_type' => $user->profile->type_of_car,
                'fee_deducted' => $requiredFee,
                'payment_method' => $request->payment_method // Log payment method
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
     * Get multiple route options between two points
     */
    public function getRouteOptions(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'pickup_lat' => 'required|numeric',
            'pickup_lng' => 'required|numeric',
            'destination_lat' => 'required|numeric',
            'destination_lng' => 'required|numeric',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $origin = [
                'lat' => $request->pickup_lat,
                'lng' => $request->pickup_lng
            ];

            $destination = [
                'lat' => $request->destination_lat,
                'lng' => $request->destination_lng
            ];

            $routes = $this->geo->getRouteAlternatives(
                $origin,
                $destination,
                3 // Get up to 3 alternatives
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'origin' => $origin,
                    'destination' => $destination,
                    'routes' => $routes,
                ]
            ], 200);

        } catch (\Exception $e) {
            Log::error('Route options failed', [
                'error' => $e->getMessage(),
                'request' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to get route options: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create a ride with a pre-selected route
     */
    /**
     * Create a ride with a pre-selected route (supports addresses or coordinates)
     */
    /**
     * Create a ride with a pre-selected route (supports addresses or coordinates)
     */
    public function createRideWithRoute(Request $request)
    {
        $user = $request->user();

        // Verify driver status
        if (!$user->is_verified_driver) {
            return response()->json([
                'success' => false,
                'message' => 'You must be verified as a driver to create rides.'
            ], 403);
        }

        $this->validateDriverProfile($user);

        // Input validation
        $validator = Validator::make($request->all(), [
            'pickup_address'       => 'required_without_all:pickup_lat,pickup_lng|string|max:255',
            'pickup_lat'           => 'required_with:pickup_lng|numeric',
            'pickup_lng'           => 'required_with:pickup_lat|numeric',
            'destination_address'  => 'required_without_all:destination_lat,destination_lng|string|max:255',
            'destination_lat'      => 'required_with:destination_lng|numeric',
            'destination_lng'      => 'required_with:destination_lat|numeric',
            'route_index'          => 'required|integer|min:0',
            'departure_time'       => [
                'required',
                'date',
                function ($attribute, $value, $fail) {
                    try {
                        $inputTime = Carbon::parse($value, 'Asia/Damascus');
                        $now = Carbon::now('Asia/Damascus');

                        if ($inputTime->lte($now->addMinutes(5))) {
                            $fail('Departure time must be at least 5 minutes in the future (Syria time).');
                        }
                    } catch (\Exception $e) {
                        $fail('Invalid date format.');
                    }
                }
            ],
            'available_seats'      => 'required|integer|min:1|max:8',
            'price_per_seat'       => 'required|numeric|min:100|max:100000',
            'payment_method'       => 'required|in:cash,e-pay',
            'communication_number' => 'required|regex:/^09\d{8}$/',
            'booking_type'         => 'required|in:direct,request',
            'notes'                => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            DB::beginTransaction();

            // Parse departure time (assuming Syria timezone)
            $departureTime = Carbon::parse($request->departure_time);

            Log::info('Creating ride with departure time', [
                'driver_id' => $user->id,
                'input_departure_time' => $request->departure_time,
                'parsed_departure_time' => $departureTime->toDateTimeString(),
            ]);

            // Calculate ride creation fee (5% of total ride value)
            $totalRidePrice = $request->price_per_seat * $request->available_seats;
            $requiredFee = $totalRidePrice * 0.05;

            // Get and lock driver wallet
            $driverWallet = Wallet::where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if (!$driverWallet) {
                throw new \Exception('Driver wallet not found. Please create a wallet first.');
            }

            // Verify sufficient balance for fee
            if ($driverWallet->balance < $requiredFee) {
                throw new \Exception(
                    sprintf(
                        'Insufficient wallet balance. Required fee: %s SYP. Current balance: %s SYP.',
                        number_format($requiredFee, 0),
                        number_format($driverWallet->balance, 0)
                    )
                );
            }

            // Get and lock SyCash admin wallet
            $syCashConfig = AdminDashboardController::ADMIN_CONFIGS['sycash'];
            $syCashWallet = Wallet::where('phone_number', $syCashConfig['phone'])
                ->lockForUpdate()
                ->first();

            if (!$syCashWallet) {
                throw new \Exception('SyCash system wallet not found. Please contact support.');
            }

            // Process location data
            if ($request->filled('pickup_address')) {
                $pickup = $this->geo->geocodeAddress($request->pickup_address);
                $pickupAddress = $request->pickup_address;
            } else {
                $pickup = [
                    'lat' => (float)$request->pickup_lat,
                    'lng' => (float)$request->pickup_lng,
                ];
                $pickupAddress = $this->geo->reverseGeocode($pickup['lat'], $pickup['lng']);
            }

            if ($request->filled('destination_address')) {
                $destination = $this->geo->geocodeAddress($request->destination_address);
                $destinationAddress = $request->destination_address;
            } else {
                $destination = [
                    'lat' => (float)$request->destination_lat,
                    'lng' => (float)$request->destination_lng,
                ];
                $destinationAddress = $this->geo->reverseGeocode($destination['lat'], $destination['lng']);
            }

            // Get route options and validate selection
            $routeOptions = $this->geo->getRouteAlternatives($pickup, $destination, 3);

            if ($request->route_index >= count($routeOptions)) {
                throw new \Exception(sprintf(
                    'Invalid route selection. Available routes: 0-%d. Selected: %d',
                    count($routeOptions) - 1,
                    $request->route_index
                ));
            }

            $chosenRoute = $routeOptions[$request->route_index];

            // Process wallet transactions for ride creation fee
            $driverPreviousBalance = $driverWallet->balance;
            $driverWallet->balance -= $requiredFee;
            $driverWallet->save();

            $syCashPreviousBalance = $syCashWallet->balance;
            $syCashWallet->balance += $requiredFee;
            $syCashWallet->save();

            // Create transaction records
            $transactionId = 'RIDE_FEE_' . time() . '_' . Str::random(6);

            // Driver transaction (debit)
            WalletTransaction::create([
                'wallet_id' => $driverWallet->id,
                'user_id' => $user->id,
                'type' => 'ride_creation_fee',
                'amount' => -$requiredFee,
                'previous_balance' => $driverPreviousBalance,
                'new_balance' => $driverWallet->balance,
                'description' => sprintf(
                    'Ride creation fee: %s to %s (Route %d)',
                    $pickupAddress,
                    $destinationAddress,
                    $request->route_index + 1
                ),
                'transaction_id' => $transactionId,
                'status' => 'completed',
                'metadata' => [
                    'ride_type' => 'pre_routed',
                    'booking_type' => $request->booking_type,
                    'total_ride_price' => $totalRidePrice,
                    'fee_percentage' => 5,
                    'payment_method' => $request->payment_method,
                    'route_index' => $request->route_index,
                    'distance_km' => round($chosenRoute['distance'] / 1000, 2),
                    'duration_minutes' => round($chosenRoute['duration'] / 60)
                ]
            ]);

            // SyCash transaction (credit)
            WalletTransaction::create([
                'wallet_id' => $syCashWallet->id,
                'user_id' => $syCashWallet->user_id,
                'type' => 'ride_creation_fee',
                'amount' => $requiredFee,
                'previous_balance' => $syCashPreviousBalance,
                'new_balance' => $syCashWallet->balance,
                'description' => sprintf(
                    'Ride creation fee from %s %s',
                    $user->first_name,
                    $user->last_name
                ),
                'transaction_id' => 'SYCA_' . $transactionId,
                'status' => 'completed',
                'metadata' => [
                    'driver_id' => $user->id,
                    'driver_name' => $user->first_name . ' ' . $user->last_name,
                    'driver_email' => $user->email,
                    'fee_percentage' => 5,
                    'total_ride_price' => $totalRidePrice,
                    'booking_type' => $request->booking_type,
                    'payment_method' => $request->payment_method
                ]
            ]);

            // Prepare ride data
            $rideData = [
                'driver_id' => $user->id,
                'pickup_address' => $pickupAddress,
                'destination_address' => $destinationAddress,
                'pickup_location' => [
                    'lat' => $pickup['lat'],
                    'lng' => $pickup['lng'],
                ],
                'destination_location' => [
                    'lat' => $destination['lat'],
                    'lng' => $destination['lng'],
                ],
                'distance' => $chosenRoute['distance'],
                'duration' => $chosenRoute['duration'],
                'route_geometry' => $chosenRoute['geometry'],
                'chosen_route_index' => $request->route_index,
                'departure_time' => $departureTime->toDateTimeString(),
                'available_seats' => $request->available_seats,
                'price_per_seat' => $request->price_per_seat,
                'vehicle_type' => $user->profile->type_of_car ?? 'Not specified',
                'communication_number' => $request->communication_number,
                'payment_method' => $request->payment_method,
                'booking_type' => $request->booking_type,
                'notes' => $request->notes,
            ];

            // Create the ride
            $ride = $this->rideRepository->createRideWithGeometry($rideData);

            // Update driver's ride count
            $user->profile->increment('number_of_rides');

            // Send notifications to nearby passengers
            $this->notifyNearbyPassengers($ride, $pickup, $destination);

            // Create success notification for driver
            $this->notificationService->createNotification(
                $user,
                'ride_created_success',
                'Ride Created Successfully',
                sprintf(
                    'Your ride from %s to %s has been created and is available for booking. Departure: %s',
                    $pickupAddress,
                    $destinationAddress,
                    $departureTime->format('M j, Y \a\t g:i A')
                ),
                [
                    'ride_id' => $ride->id,
                    'pickup_address' => $pickupAddress,
                    'destination_address' => $destinationAddress,
                    'departure_time' => $departureTime->toISOString(),
                    'payment_method' => $request->payment_method,
                    'booking_type' => $request->booking_type,
                    'fee_deducted' => $requiredFee,
                    'distance_km' => round($chosenRoute['distance'] / 1000, 1),
                    'duration_minutes' => round($chosenRoute['duration'] / 60)
                ],
                'normal',
                'ride'
            );

            // Broadcast ride creation event
            broadcast(new RideCreated($ride));

            DB::commit();

            Log::info('Ride with route created successfully', [
                'ride_id' => $ride->id,
                'driver_id' => $user->id,
                'pickup' => $pickupAddress,
                'destination' => $destinationAddress,
                'departure_syria_time' => $departureTime->toDateTimeString(),
                'route_index' => $request->route_index,
                'distance_km' => round($chosenRoute['distance'] / 1000, 1),
                'duration_minutes' => round($chosenRoute['duration'] / 60),
                'fee_deducted' => $requiredFee,
                'payment_method' => $request->payment_method,
                'booking_type' => $request->booking_type,
                'driver_new_balance' => $driverWallet->balance
            ]);

            return response()->json([
                'success' => true,
                'data' => $this->formatRideResponse($ride),
                'message' => 'Ride created successfully'
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Ride creation with route failed', [
                'driver_id' => $user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->except(['password'])
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Ride creation failed: ' . $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Book a ride with payment method handling
     */
    // RideController.php - bookRide method
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
            'communication_number' => 'required|regex:/^09\d{8}$/',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            DB::beginTransaction();
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

            // Calculate total cost
            $totalCost = $request->input('seats') * $ride->price_per_seat;
            $seats = $request->input('seats');

            // Determine booking status based on ride type
            $bookingStatus = ($ride->booking_type === 'direct')
                ? Booking::CONFIRMED
                : Booking::PENDING;

            $booking = $this->rideRepository->bookRide($rideId, [
                'user_id' => $user->id,
                'seats' => $seats,
                'status' => $bookingStatus,
                'communication_number' => $request->input('communication_number'),
            ]);

            // Handle payment for direct bookings
            if ($ride->booking_type === 'direct') {
                if ($ride->payment_method === 'e-pay') {
                    $passengerWallet = Wallet::where('user_id', $user->id)->firstOrFail();
                    $adminWallet = Wallet::whereHas('user', function($query) {
                        $query->where('email', 'twisrmann2002@gmail.com');
                    })->firstOrFail();

                    $this->processWalletTransfer(
                        $passengerWallet,
                        $adminWallet,
                        $totalCost,
                        $booking,
                        $ride
                    );
                    $paymentMessage = "Payment of $" . number_format($totalCost, 2) . " has been processed.";
                } else {
                    $paymentMessage = "You'll pay the driver $" . number_format($totalCost, 2) . " in cash.";
                }

                $notificationType = 'ride_booked';
                $passengerTitle = 'Booking Confirmed';
                $passengerMessage = "Your booking is confirmed! " . $paymentMessage;
                $driverTitle = 'New Ride Booking';
                $driverMessage = "{$user->first_name} {$user->last_name} has booked {$seats} seat(s) for your ride";
            } else {
                $paymentMessage = "Your request has been sent to the driver";
                $notificationType = 'booking_requested';
                $passengerTitle = 'Request Sent';
                $passengerMessage = "Booking request sent. Waiting for driver approval";
                $driverTitle = 'New Booking Request';
                $driverMessage = "{$user->first_name} {$user->last_name} has requested {$seats} seat(s) for your ride";
            }

            // Notify driver
            $this->notificationService->createNotification(
                $ride->driver,
                $notificationType,
                $driverTitle,
                $driverMessage . " from {$ride->pickup_address} to {$ride->destination_address}.",
                [
                    'ride_id' => $ride->id,
                    'booking_id' => $booking->id,
                    'passenger_id' => $user->id,
                    'passenger_name' => "{$user->first_name} {$user->last_name}",
                    'seats_booked' => $seats,
                    'total_price' => $totalCost,
                    'payment_method' => $ride->payment_method,
                    'booking_type' => $ride->booking_type,
                    'pickup_address' => $ride->pickup_address,
                    'destination_address' => $ride->destination_address,
                    'departure_time' => $ride->departure_time->toISOString(),
                ],
                'high',
                'ride'
            );

            // Notify passenger
            $this->notificationService->createNotification(
                $user,
                ($ride->booking_type === 'direct') ? 'booking_confirmed' : 'booking_requested',
                $passengerTitle,
                $passengerMessage,
                [
                    'ride_id' => $ride->id,
                    'booking_id' => $booking->id,
                    'driver_id' => $ride->driver_id,
                    'driver_name' => "{$ride->driver->first_name} {$ride->driver->last_name}",
                    'seats_booked' => $seats,
                    'total_price' => $totalCost,
                    'payment_method' => $ride->payment_method,
                    'pickup_address' => $ride->pickup_address,
                    'destination_address' => $ride->destination_address,
                    'departure_time' => $ride->departure_time->toISOString(),
                ],
                'normal',
                'ride'
            );

            broadcast(new RideBooked($ride, $booking, $user));

            // Check if ride is full
            $remainingSeats = $ride->available_seats - $ride->bookings()->sum('seats');
            if ($remainingSeats <= 0) {
                $this->notifyRideFull($ride);

                // Update ride status to 'full'
                $ride->status = 'full';
                $ride->save();
            }

            DB::commit();

            Log::info('Ride booking successful', [
                'ride_id' => $ride->id,
                'booking_id' => $booking->id,
                'passenger_id' => $user->id,
                'booking_type' => $ride->booking_type,
                'status' => $bookingStatus,
                'total_cost' => $totalCost,
                'payment_method' => $ride->payment_method,
                'new_ride_status' => $ride->status // Log new status
            ]);

            return response()->json([
                'success' => true,
                'data' => $this->formatBookingResponse($booking),
                'message' => ($ride->booking_type === 'direct')
                    ? 'Ride booked successfully! ' . $paymentMessage
                    : 'Booking request sent successfully!',
                'payment_info' => ($ride->booking_type === 'direct' && $ride->payment_method === 'e-pay')
                    ? ['amount_paid' => $totalCost, 'remaining_balance' => $passengerWallet->fresh()->balance]
                    : null
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
     * Process wallet transfer from passenger to admin
     */
    private function processWalletTransfer($passengerWallet, $adminWallet, $amount, $booking, $ride)
    {
        // Update passenger wallet (deduct money)
        $passengerPreviousBalance = $passengerWallet->balance;
        $passengerNewBalance = $passengerPreviousBalance - $amount;
        $passengerWallet->balance = $passengerNewBalance;
        $passengerWallet->save();

        // Update admin wallet (add money)
        $adminPreviousBalance = $adminWallet->balance;
        $adminNewBalance = $adminPreviousBalance + $amount;
        $adminWallet->balance = $adminNewBalance;
        $adminWallet->save();

        // Create transaction record for passenger (debit)
        WalletTransaction::create([
            'wallet_id' => $passengerWallet->id,
            'user_id' => $passengerWallet->user_id,
            'type' => 'ride_booking_payment',
            'amount' => -$amount, // Negative for debit
            'previous_balance' => $passengerPreviousBalance,
            'new_balance' => $passengerNewBalance,
            'description' => "Payment for {$booking->seats} seat(s) on ride from {$ride->pickup_address} to {$ride->destination_address}",
            'transaction_id' => 'RB_' . time() . '_' . Str::random(8),
            'status' => 'completed',
            'metadata' => [
                'ride_id' => $ride->id,
                'booking_id' => $booking->id,
                'seats_booked' => $booking->seats,
                'price_per_seat' => $ride->price_per_seat,
                'driver_id' => $ride->driver_id,
                'pickup_address' => $ride->pickup_address,
                'destination_address' => $ride->destination_address,
                'departure_time' => $ride->departure_time->toDateTimeString(),
                'transaction_type' => 'debit'
            ]
        ]);

        // Create transaction record for admin (credit)
        WalletTransaction::create([
            'wallet_id' => $adminWallet->id,
            'user_id' => $adminWallet->user_id,
            'type' => 'ride_booking_received',
            'amount' => $amount, // Positive for credit
            'previous_balance' => $adminPreviousBalance,
            'new_balance' => $adminNewBalance,
            'description' => "Payment received for ride booking - {$booking->seats} seat(s) from {$passengerWallet->user->first_name} {$passengerWallet->user->last_name}",
            'transaction_id' => 'RBR_' . time() . '_' . Str::random(8),
            'status' => 'completed',
            'metadata' => [
                'ride_id' => $ride->id,
                'booking_id' => $booking->id,
                'passenger_id' => $passengerWallet->user_id,
                'passenger_name' => "{$passengerWallet->user->first_name} {$passengerWallet->user->last_name}",
                'seats_booked' => $booking->seats,
                'price_per_seat' => $ride->price_per_seat,
                'driver_id' => $ride->driver_id,
                'pickup_address' => $ride->pickup_address,
                'destination_address' => $ride->destination_address,
                'departure_time' => $ride->departure_time->toDateTimeString(),
                'transaction_type' => 'credit'
            ]
        ]);

        Log::info('Wallet transfer completed', [
            'passenger_wallet_id' => $passengerWallet->id,
            'admin_wallet_id' => $adminWallet->id,
            'amount' => $amount,
            'passenger_balance' => $passengerNewBalance,
            'admin_balance' => $adminNewBalance,
            'ride_id' => $ride->id,
            'booking_id' => $booking->id
        ]);
    }
    /**
     * Cancel a ride
     */
    public function cancelRide(Request $request, int $rideId)
    {
        $user = $request->user();

        return DB::transaction(function () use ($user, $rideId) {
            $ride = Ride::with('bookings.user')->lockForUpdate()->findOrFail($rideId);

            if ($ride->driver_id !== $user->id) {
                abort(403, 'Only the ride driver can cancel the ride');
            }

            $bookings = $ride->bookings()
                ->whereIn('status', [Booking::PENDING, Booking::CONFIRMED])
                ->get();

            // 1. mark every seat as cancelled
            foreach ($bookings as $booking) {
                $booking->status = Booking::CANCELLED;
                $booking->save();
            }

            // 2. give seats back
            $ride->available_seats += $bookings->sum('seats');
            $ride->status          = 'cancelled';
            $ride->save();

            // 3. notify passengers
            foreach ($bookings as $booking) {
                $this->notificationService->createNotification(
                    $booking->user,
                    'ride_cancelled',
                    'Ride Cancelled',
                    "The ride from {$ride->pickup_address} to {$ride->destination_address} was cancelled. Your refund is on the way.",
                    [
                        'ride_id'       => $ride->id,
                        'booking_id'    => $booking->id,
                        'refund_amount' => $booking->seats * $ride->price_per_seat,
                    ],
                    'high',
                    'ride'
                );
            }

            broadcast(new RideCancelled($ride, $bookings->toArray(), $user));

            return response()->json([
                'success' => true,
                'data'    => $this->formatRideResponse($ride),
                'message' => "Ride cancelled. {$bookings->count()} passenger(s) refunded.",
            ]);
        });
    }

    /**
     * Get ride details
     */
    public function getRideDetails(int $rideId)
    {
        try {
            // Load the ride with all necessary relationships
            $ride = Ride::with([
                'driver.profile',
                'bookings.user.profile'
            ])->findOrFail($rideId);

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
     * Get user's rides - Updated to load driver profiles
     */
    public function getRides(Request $request)
    {
        $user = $request->user();

        try {
            // Load rides with driver profiles
            $rides = Ride::with('driver.profile')
                ->where('driver_id', $user->id)
                ->orderBy('created_at', 'desc')
                ->get();

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
            // Handle source location
            if ($request->filled('source_address')) {
                $passengerSource = $this->geo->geocodeAddress($request->source_address);
            } else {
                $passengerSource = [
                    'lat'   => (float)$request->source_lat,
                    'lng'   => (float)$request->source_lng,
                    'label' => null,
                ];
            }

            // Handle destination location
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
    /**
     * Validate driver profile completeness - only check essential verification documents
     */
    private function validateDriverProfile($user) {
        // Only check essential verification documents
        $requiredFields = [
            'face_id_pic',         // Required for identity verification
            'back_id_pic',         // Required for identity verification
            'driving_license_pic', // Required for driver verification
            'mechanic_card_pic'    // Required for vehicle safety verification
        ];

        $missingFields = [];

        foreach ($requiredFields as $field) {
            if (!$user->profile || empty($user->profile->$field)) {
                $missingFields[] = $field;
            }
        }

        if (!empty($missingFields)) {
            $fieldNames = [
                'face_id_pic' => 'Face ID Photo',
                'back_id_pic' => 'Back ID Photo',
                'driving_license_pic' => 'Driving License Photo',
                'mechanic_card_pic' => 'Mechanic Card Photo'
            ];

            $missingNames = array_map(fn($field) => $fieldNames[$field], $missingFields);

            abort(response()->json([
                'success' => false,
                'message' => 'Missing required verification documents: ' . implode(', ', $missingNames),
                'missing_fields' => $missingFields
            ], 403));
        }
    }

    /**
     * Format ride response
     */
    private function formatRideResponse($ride)
    {
        // Load the driver's profile if not already loaded
        if (!$ride->relationLoaded('driver.profile')) {
            $ride->load('driver.profile');
        }

        return [
            'id' => $ride->id,
            'driver' => [
                'id'     => $ride->driver->id,
                'name'   => trim($ride->driver->first_name . ' ' . $ride->driver->last_name),
                'avatar' => $ride->driver->profile && $ride->driver->profile->profile_photo
                    ? asset('storage/' . $ride->driver->profile->profile_photo)
                    : $ride->driver->avatar,
                'rating' => $ride->driver->driver_rating ?? 0,
            ],
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
            'seats_booked' => (int) ($ride->bookings()->sum('seats') ?? 0),
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
            'payment_method' => $ride->payment_method,
            'booking_type' => $ride->booking_type,
            'notes' => $ride->notes,
            'created_at' => $ride->created_at->toIso8601String(),
            'chosen_route_index' => $ride->chosen_route_index,
            'route_geometry' => $ride->route_geometry,
            'communication_number' => $ride->communication_number,
        ];
    }

    /**
     * Format ride details response - Updated to include driver profile photo
     */
    private function formatRideDetailsResponse($ride)
    {
        // Load the driver's profile and bookings with users' profiles
        if (!$ride->relationLoaded('driver.profile')) {
            $ride->load('driver.profile');
        }

        if (!$ride->relationLoaded('bookings.user.profile')) {
            $ride->load('bookings.user.profile');
        }

        return array_merge($this->formatRideResponse($ride), [
            'route_geometry' => $ride->route_geometry,
            'bookings' => $ride->bookings->map(fn ($booking) => [
                'id' => $booking->id,
                'user' => [
                    'id'   => $booking->user->id,
                    'name' => $booking->user->first_name . ' ' . $booking->user->last_name,
                    'avatar' => $booking->user->profile && $booking->user->profile->profile_photo
                        ? asset('storage/' . $booking->user->profile->profile_photo)
                        : $booking->user->avatar,
                    'rating' => $booking->user->passenger_rating ?? 0,
                ],
                'seats' => (int) $booking->seats,
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
            'status' => $booking->status,
            'communication_number' => $booking->communication_number,
            'total_price' => $booking->seats * $booking->ride->price_per_seat,
            'booking_date' => $booking->created_at->toIso8601String(),
            'ride_details' => [
                'pickup_address' => $booking->ride->pickup_address,
                'destination_address' => $booking->ride->destination_address,
                'departure_time' => $booking->ride->departure_time->toIso8601String(),
            ]
        ];
    }
    /**
     * Finish a ride - Changes status from active to finished and transfers money to driver
     * POST /api/rides/{rideId}/finish
     */
    // Add these methods to your RideController

    /**
     * Driver confirms ride completion
     * POST /api/rides/{rideId}/driver-confirm
     */
    public function driverConfirmCompletion(Request $request, int $rideId)
    {
        $user = $request->user();

        try {
            $ride = $this->rideRepository->getRideById($rideId);

            // Verify user is the driver
            if ($ride->driver_id !== $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only the ride driver can confirm completion'
                ], 403);
            }

            // Ensure ride is in awaiting_confirmation status
            if ($ride->status !== 'awaiting_confirmation') {
                return response()->json([
                    'success' => false,
                    'message' => 'Ride is not in confirmation state'
                ], 400);
            }

            DB::beginTransaction();
            $ride->driver_confirmed_at = now();
            $ride->save();
            DB::commit();

            // Check if all passengers have confirmed
            $this->checkRideCompletionStatus($ride);

            return response()->json([
                'success' => true,
                'message' => 'Driver confirmation received. Waiting for passenger confirmations.'
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Confirmation failed: ' . $e->getMessage()
            ], 400);
        }
    }

    /**
     * Passenger confirms ride completion
     * POST /api/bookings/{bookingId}/passenger-confirm
     */
    public function passengerConfirmCompletion(Request $request, int $bookingId)
    {
        $user = $request->user();

        try {
            $booking = Booking::with('ride')->findOrFail($bookingId);
            $ride = $booking->ride;

            // Verify user is the passenger
            if ($booking->user_id !== $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only the booking passenger can confirm completion'
                ], 403);
            }

            // Ensure ride is in awaiting_confirmation status
            if ($ride->status !== 'awaiting_confirmation') {
                return response()->json([
                    'success' => false,
                    'message' => 'Ride is not in confirmation state'
                ], 400);
            }

            DB::beginTransaction();
            $booking->passenger_confirmed_at = now();
            $booking->save();
            DB::commit();

            $this->checkRideCompletionStatus($ride);

            return response()->json([
                'success' => true,
                'message' => 'Passenger confirmation received.'
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Confirmation failed: ' . $e->getMessage()
            ], 400);
        }
    }

    /**
     * Check if all parties have confirmed and complete the ride
     */
    private function checkRideCompletionStatus(Ride $ride)
    {
        $confirmedBookings = $ride->bookings()
            ->whereNotNull('passenger_confirmed_at')
            ->count();

        $totalBookings = $ride->bookings()->count();

        if ($ride->driver_confirmed_at && $confirmedBookings === $totalBookings) {
            $this->completeRideAndReleasePayments($ride);
        }
    }

    /**
     * Update the existing finishRide method
     * POST /api/rides/{rideId}/finish
     */
    public function finishRide(Request $request, int $rideId)
    {
        $user = $request->user();

        try {
            DB::beginTransaction();

            // Get ride with all bookings
            $ride = $this->rideRepository->getRideById($rideId);

            // Check if user is the driver
            if ($ride->driver_id !== $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only the ride driver can finish the ride'
                ], 403);
            }

            if (!in_array($ride->status, ['active', 'full'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only active or full rides can be finished'
                ], 400);
            }

            // Get all confirmed bookings
            $confirmedBookings = $ride->bookings()
                ->where('status', 'confirmed')
                ->with('user')
                ->get();

            if ($confirmedBookings->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No confirmed bookings found for this ride'
                ], 400);
            }

            // Update ride status to awaiting_confirmation
            $ride->status = 'awaiting_confirmation';
            $ride->finished_at = now();
            $ride->save();

            DB::commit();

            // Notify driver and passengers
            $this->notifyForConfirmation($ride);

            return response()->json([
                'success' => true,
                'message' => 'Ride completed! Please confirm completion to release funds.'
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Ride completion failed: ' . $e->getMessage()
            ], 400);
        }
    }

    /**
     * Notify driver and passengers to confirm ride completion
     */
    private function notifyForConfirmation(Ride $ride)
    {
        // Notify driver
        $this->notificationService->createNotification(
            $ride->driver,
            'driver_confirmation_needed',
            'Confirm Ride Completion',
            "Please confirm you've completed the ride to receive payment",
            [
                'ride_id' => $ride->id,
                'action_route' => "/api/rides/{$ride->id}/driver-confirm"
            ],
            'high',
            'ride'
        );

        // Notify passengers
        foreach ($ride->bookings as $booking) {
            $this->notificationService->createNotification(
                $booking->user,
                'passenger_confirmation_needed',
                'Confirm Ride Completion',
                "Please confirm the ride was completed to release payment to the driver",
                [
                    'ride_id' => $ride->id,
                    'booking_id' => $booking->id,
                    'action_route' => "/api/bookings/{$booking->id}/passenger-confirm"
                ],
                'normal',
                'ride'
            );
        }
    }

    /**
     * Complete ride and release payments (called when all confirmations are received)
     */
    /**
     * Complete ride and release payments (called when all confirmations are received)
     */
    private function completeRideAndReleasePayments(Ride $ride)
    {
        try {
            DB::beginTransaction();

            // Get all confirmed bookings
            $confirmedBookings = $ride->bookings()
                ->where('status', 'confirmed')
                ->with('user')
                ->get();

            // Only process payments for e-pay rides
            if ($ride->payment_method === 'e-pay') {
                // Calculate total amount to transfer to driver
                $totalAmount = $confirmedBookings->sum(function ($booking) use ($ride) {
                    return $booking->seats * $ride->price_per_seat;
                });

                // Lock wallets for update to prevent concurrent access
                $adminWallet = Wallet::whereHas('user', function($query) {
                    $query->where('email', 'twisrmann2002@gmail.com');
                })->lockForUpdate()->first();

                $driverWallet = Wallet::where('user_id', $ride->driver_id)
                    ->lockForUpdate()
                    ->first();

                if (!$adminWallet || !$driverWallet) {
                    throw new \Exception('Admin or driver wallet not found');
                }

                // Get current balances AFTER locking
                $adminBalance = $adminWallet->balance;
                $driverBalance = $driverWallet->balance;

                // Verify admin has sufficient funds
                if ($adminBalance < $totalAmount) {
                    throw new \Exception("Insufficient admin wallet balance. Required: $totalAmount, Available: $adminBalance");
                }

                // Perform atomic balance updates
                $adminWallet->balance = $adminBalance - $totalAmount;
                $driverWallet->balance = $driverBalance + $totalAmount;

                $adminWallet->save();
                $driverWallet->save();

                // Create transaction records
                $this->createRideCompletionTransactions(
                    $adminWallet,
                    $driverWallet,
                    $adminBalance,
                    $driverBalance,
                    $totalAmount,
                    $ride,
                    $confirmedBookings
                );
            } else {
                // For cash rides, log that no transfer occurred
                Log::info('Cash ride completed - no wallet transfer', [
                    'ride_id' => $ride->id,
                    'driver_id' => $ride->driver_id,
                    'total_cash_amount' => $confirmedBookings->sum(function ($booking) use ($ride) {
                        return $booking->seats * $ride->price_per_seat;
                    })
                ]);
            }

            // Update ride status
            $ride->status = 'finished';
            $ride->passengers_confirmed = true;
            $ride->save();

            // Update bookings
            foreach ($confirmedBookings as $booking) {
                $booking->status = 'completed';
                $booking->completed_at = now();
                $booking->save();
            }

            DB::commit();

            // Send notifications
            $this->sendCompletionNotifications($ride, $totalAmount ?? 0);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Ride completion failed', [
                'ride_id' => $ride->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }


    /**
     * Send completion notifications
   */
    private function sendCompletionNotifications(Ride $ride, $amount)
    {
        // Notify driver
        $this->notificationService->createNotification(
            $ride->driver,
            'ride_completed_earnings',
            'Ride Completed - Payment Received',
            "You've received $" . number_format($amount, 2) . " for your ride",
            ['ride_id' => $ride->id],
            'high',
            'ride'
        );

        // Notify passengers
        foreach ($ride->bookings as $booking) {
            $this->notificationService->createNotification(
                $booking->user,
                'ride_completed',
                'Ride Completed',
                "The ride has been completed and payment released to the driver",
                ['ride_id' => $ride->id],
                'normal',
                'ride'
            );
        }
    }

    /**
     * Process wallet transfer from admin to driver when ride is completed
     */
    private function processRideCompletionTransfer($adminWallet, $driverWallet, $amount, $ride, $bookings)
    {
        try {
            // Refresh wallets to get current balances
            $adminWallet = $adminWallet->fresh();
            $driverWallet = $driverWallet->fresh();

            // Update admin wallet (deduct money)
            $adminPreviousBalance = $adminWallet->balance;
            $adminNewBalance = $adminPreviousBalance - $amount;
            $adminWallet->balance = $adminNewBalance;
            $adminWallet->save();

            // Update driver wallet (add money)
            $driverPreviousBalance = $driverWallet->balance;
            $driverNewBalance = $driverPreviousBalance + $amount;
            $driverWallet->balance = $driverNewBalance;
            $driverWallet->save();

            // Generate transaction ID
            $transactionId = 'RIDE_COMPLETION_' . time() . '_' . Str::random(8);

            // Create transaction record for admin (debit)
            WalletTransaction::create([
                'wallet_id' => $adminWallet->id,
                'user_id' => $adminWallet->user_id,
                'type' => 'ride_completion_payment',
                'amount' => -$amount,
                'previous_balance' => $adminPreviousBalance,
                'new_balance' => $adminNewBalance,
                'description' => "Payment to driver for completed ride from {$ride->pickup_address} to {$ride->destination_address}",
                'transaction_id' => 'ADMIN_' . $transactionId,
                'status' => 'completed',
                'metadata' => [
                    'ride_id' => $ride->id,
                    'driver_id' => $ride->driver_id,
                    'driver_name' => "{$ride->driver->first_name} {$ride->driver->last_name}",
                    'passengers_count' => $bookings->count(),
                    'total_seats' => $bookings->sum('seats'),
                    'pickup_address' => $ride->pickup_address,
                    'destination_address' => $ride->destination_address,
                    'completion_time' => now()->toDateTimeString(),
                    'transaction_type' => 'debit'
                ]
            ]);

            // Create transaction record for driver (credit)
            WalletTransaction::create([
                'wallet_id' => $driverWallet->id,
                'user_id' => $driverWallet->user_id,
                'type' => 'ride_completion_earnings',
                'amount' => $amount,
                'previous_balance' => $driverPreviousBalance,
                'new_balance' => $driverNewBalance,
                'description' => "Earnings from completed ride from {$ride->pickup_address} to {$ride->destination_address}",
                'transaction_id' => 'DRIVER_' . $transactionId,
                'status' => 'completed',
                'metadata' => [
                    'ride_id' => $ride->id,
                    'passengers_served' => $bookings->count(),
                    'total_seats' => $bookings->sum('seats'),
                    'earnings_breakdown' => $bookings->map(function($booking) use ($ride) {
                        return [
                            'passenger_id' => $booking->user_id,
                            'passenger_name' => "{$booking->user->first_name} {$booking->user->last_name}",
                            'seats' => $booking->seats,
                            'amount' => $booking->seats * $ride->price_per_seat
                        ];
                    })->toArray(),
                    'pickup_address' => $ride->pickup_address,
                    'destination_address' => $ride->destination_address,
                    'completion_time' => now()->toDateTimeString(),
                    'transaction_type' => 'credit'
                ]
            ]);

            Log::info('Ride completion wallet transfer completed', [
                'admin_wallet_id' => $adminWallet->id,
                'driver_wallet_id' => $driverWallet->id,
                'amount' => $amount,
                'admin_previous_balance' => $adminPreviousBalance,
                'admin_new_balance' => $adminNewBalance,
                'driver_previous_balance' => $driverPreviousBalance,
                'driver_new_balance' => $driverNewBalance,
                'ride_id' => $ride->id,
                'transaction_id' => $transactionId
            ]);

        } catch (\Exception $e) {
            Log::error('Ride completion wallet transfer failed', [
                'admin_wallet_id' => $adminWallet->id,
                'driver_wallet_id' => $driverWallet->id,
                'amount' => $amount,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }
    // RideController.php - acceptBooking method
    public function acceptBooking(Request $request, int $bookingId)
    {
        $user = $request->user();
        return DB::transaction(function () use ($user, $bookingId) {
            // Load booking with relationships and lock for update
            $booking = Booking::with(['ride', 'user.wallet'])
                ->lockForUpdate()
                ->findOrFail($bookingId);

            $ride = $booking->ride;
            $passenger = $booking->user;

            // Validate driver ownership
            if ($ride->driver_id !== $user->id) {
                abort(403, 'Only the ride driver can accept bookings');
            }

            // Validate booking type
            if ($ride->booking_type !== 'request') {
                abort(400, 'Accept/Reject available only for request-type rides');
            }

            // Validate booking status
            if ($booking->status !== Booking::PENDING) {
                abort(400, 'Booking already handled');
            }

            // Check available seats
            if ($ride->available_seats < $booking->seats) {
                abort(400, 'Not enough available seats');
            }

            // Update booking status
            $booking->status = Booking::CONFIRMED;
            $booking->save();

            // Decrement available seats
            $ride->decrement('available_seats', $booking->seats);

            // Calculate total cost
            $totalCost = $booking->seats * $ride->price_per_seat;

            // Process payment for e-pay rides
            $paymentProcessed = false;
            if ($ride->payment_method === 'e-pay') {
                // Get passenger wallet with lock
                $passengerWallet = Wallet::where('user_id', $passenger->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                // Get admin wallet
                $adminWallet = Wallet::whereHas('user', function($query) {
                    $query->where('email', 'twisrmann2002@gmail.com');
                })->lockForUpdate()->firstOrFail();

                // Verify passenger balance
                if ($passengerWallet->balance < $totalCost) {
                    throw new \Exception('Passenger has insufficient wallet balance');
                }

                // Process payment
                $this->processWalletTransfer(
                    $passengerWallet,
                    $adminWallet,
                    $totalCost,
                    $booking,
                    $ride
                );

                $paymentProcessed = true;
            }

            // Check if ride is full
            if ($ride->available_seats <= 0) {
                $this->notifyRideFull($ride);
                $ride->status = 'full';
                $ride->save();
            }

            // Notify passenger
            $paymentMessage = $paymentProcessed
                ? "Payment of $" . number_format($totalCost, 2) . " has been processed."
                : "You'll pay the driver $" . number_format($totalCost, 2) . " in cash.";

            $this->notificationService->createNotification(
                $passenger,
                'booking_accepted',
                'Booking Accepted',
                "Your booking request was accepted! " . $paymentMessage,
                [
                    'ride_id' => $ride->id,
                    'booking_id' => $booking->id,
                    'driver_id' => $ride->driver_id,
                    'driver_name' => "{$ride->driver->first_name} {$ride->driver->last_name}",
                    'seats_booked' => $booking->seats,
                    'total_price' => $totalCost,
                    'payment_method' => $ride->payment_method,
                    'pickup_address' => $ride->pickup_address,
                    'destination_address' => $ride->destination_address,
                    'departure_time' => $ride->departure_time->toISOString(),
                ],
                'high',
                'ride'
            );

            // Log successful acceptance
            Log::info('Booking accepted', [
                'booking_id' => $booking->id,
                'ride_id' => $ride->id,
                'driver_id' => $user->id,
                'passenger_id' => $passenger->id,
                'seats' => $booking->seats,
                'total_cost' => $totalCost,
                'payment_processed' => $paymentProcessed,
                'new_ride_status' => $ride->status
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Booking accepted successfully',
                'payment_processed' => $paymentProcessed,
                'amount_charged' => $paymentProcessed ? $totalCost : 0,
                'ride_status' => $ride->status
            ]);
        });
    }

    public function rejectBooking(Request $request, int $bookingId)
    {
        $user = $request->user();

        return DB::transaction(function () use ($user, $bookingId) {
            $booking = Booking::with('ride')->lockForUpdate()->findOrFail($bookingId);
            $ride = $booking->ride;

            if ($ride->driver_id !== $user->id) abort(403);
            if ($ride->booking_type !== 'request') abort(400);
            if ($booking->status !== Booking::PENDING) abort(400);

            $booking->status = Booking::CANCELLED;
            $booking->save();

            // Return seat to ride
            $ride->increment('available_seats', $booking->seats);

            return response()->json([
                'success' => true,
                'message' => 'Booking rejected'
            ]);
        });
    }
    public function cancelSeat(Request $request, int $bookingId)
    {
        $user = $request->user();

        return DB::transaction(function () use ($user, $bookingId) {
            $booking = Booking::with(['ride', 'user'])->lockForUpdate()->findOrFail($bookingId);
            $ride = $booking->ride;

            if ($booking->user_id !== $user->id) {
                abort(403, 'You can only cancel your own bookings');
            }

            if (!$booking->canBeCancelled()) {
                abort(400, 'Booking cannot be cancelled');
            }

            // Calculate refund based on time elapsed
            $refundInfo = $this->calculateRefundPercentage($ride->departure_time, $booking->created_at);

            $totalSeats = $booking->seats;
            $fullPriceTotal = $totalSeats * $ride->price_per_seat;
            $refundAmount = ($fullPriceTotal * $refundInfo['refund_percentage']) / 100;
            $nonRefundableAmount = $fullPriceTotal - $refundAmount;

            // Process refund for e-pay bookings if there's a refund amount
            // Process fund redistribution for e-pay bookings (refund + driver payout)
            $refundProcessed = false;
            if ($ride->payment_method === 'e-pay' && $booking->status === 'confirmed') {
                $this->processTimeBasedRefund($booking, $ride, $refundAmount, $totalSeats, $refundInfo);
                $refundProcessed = true;
            }

            $booking->status = Booking::CANCELLED;
            $booking->cancelled_at = now();
            $booking->save();

            $ride->increment('available_seats', $totalSeats);

            // Update ride status if it was full
            if ($ride->status === 'full') {
                $ride->status = 'active';
                $ride->save();
            }

            // Send notifications
            $this->sendTimeBasedCancellationNotifications($booking, $ride, $totalSeats, $refundAmount, $refundProcessed, $refundInfo);

            Log::info('Full booking cancellation with time-based refund', [
                'booking_id' => $booking->id,
                'ride_id' => $ride->id,
                'passenger_id' => $user->id,
                'seats_cancelled' => $totalSeats,
                'time_elapsed_percentage' => $refundInfo['time_elapsed_percentage'],
                'refund_percentage' => $refundInfo['refund_percentage'],
                'refund_amount' => $refundAmount,
                'non_refundable_amount' => $nonRefundableAmount
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Booking cancelled successfully',
                'data' => [
                    'booking_id' => $booking->id,
                    'seats_cancelled' => $totalSeats,
                    'refund_policy' => [
                        'time_elapsed_percentage' => round($refundInfo['time_elapsed_percentage'], 2),
                        'refund_percentage' => $refundInfo['refund_percentage'],
                        'policy_tier' => $refundInfo['policy_tier'],
                        'total_seat_price' => $fullPriceTotal,
                        'refund_amount' => $refundAmount,
                        'non_refundable_amount' => $nonRefundableAmount,
                        'refund_processed' => $refundProcessed
                    ],
                    'booking_status' => $booking->status
                ]
            ]);
        });
    }
    private function createRideCompletionTransactions(
        $adminWallet,
        $driverWallet,
        $adminPreviousBalance,
        $driverPreviousBalance,
        $amount,
        $ride,
        $bookings
    ) {
        $transactionId = 'RIDE_COMP_' . time() . '_' . Str::random(6);

        // Admin transaction (debit)
        WalletTransaction::create([
            'wallet_id' => $adminWallet->id,
            'user_id' => $adminWallet->user_id,
            'type' => 'ride_payout',
            'amount' => -$amount,
            'previous_balance' => $adminPreviousBalance,
            'new_balance' => $adminWallet->balance,
            'description' => "Payment to driver for completed ride",
            'transaction_id' => 'ADMIN_' . $transactionId,
            'status' => 'completed',
            'metadata' => [
                'ride_id' => $ride->id,
                'driver_id' => $ride->driver_id,
                'driver_name' => "{$ride->driver->first_name} {$ride->driver->last_name}",
                'passenger_count' => $bookings->count(),
                'total_amount' => $amount,
                'pickup' => $ride->pickup_address,
                'destination' => $ride->destination_address
            ]
        ]);

        // Driver transaction (credit)
        WalletTransaction::create([
            'wallet_id' => $driverWallet->id,
            'user_id' => $driverWallet->user_id,
            'type' => 'ride_earnings',
            'amount' => $amount,
            'previous_balance' => $driverPreviousBalance,
            'new_balance' => $driverWallet->balance,
            'description' => "Earnings from completed ride",
            'transaction_id' => 'DRIVER_' . $transactionId,
            'status' => 'completed',
            'metadata' => [
                'ride_id' => $ride->id,
                'passenger_count' => $bookings->count(),
                'breakdown' => $bookings->map(function($booking) use ($ride) {
                    return [
                        'passenger_id' => $booking->user_id,
                        'seats' => $booking->seats,
                        'amount' => $booking->seats * $ride->price_per_seat
                    ];
                })->toArray(),
                'pickup' => $ride->pickup_address,
                'destination' => $ride->destination_address
            ]
        ]);

        Log::info('Ride payment processed', [
            'admin_wallet' => $adminWallet->id,
            'driver_wallet' => $driverWallet->id,
            'amount' => $amount,
            'admin_balance_before' => $adminPreviousBalance,
            'admin_balance_after' => $adminWallet->balance,
            'driver_balance_before' => $driverPreviousBalance,
            'driver_balance_after' => $driverWallet->balance
        ]);
    }
    public function cancelPartialSeats(Request $request, int $bookingId)
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'seats_to_cancel' => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            DB::beginTransaction();

            // Get booking with ride and lock for update
            $booking = Booking::with(['ride', 'user'])
                ->lockForUpdate()
                ->findOrFail($bookingId);

            $ride = $booking->ride;
            $seatsToCancel = $request->input('seats_to_cancel');

            // Verify user owns this booking
            if ($booking->user_id !== $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'You can only cancel your own bookings'
                ], 403);
            }

            // Check if booking can be cancelled
            if (!in_array($booking->status, ['pending', 'confirmed'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'This booking cannot be cancelled'
                ], 400);
            }

            // Validate seats to cancel
            if ($seatsToCancel > $booking->seats) {
                return response()->json([
                    'success' => false,
                    'message' => "Cannot cancel {$seatsToCancel} seats. You only have {$booking->seats} seats booked."
                ], 400);
            }

            // Calculate refund based on time elapsed
            $refundInfo = $this->calculateRefundPercentage($ride->departure_time, $booking->created_at);

            $fullPricePerSeat = $ride->price_per_seat;
            $totalFullPrice = $seatsToCancel * $fullPricePerSeat;
            $refundAmount = ($totalFullPrice * $refundInfo['refund_percentage']) / 100;
            $nonRefundableAmount = $totalFullPrice - $refundAmount;

            $remainingSeats = $booking->seats - $seatsToCancel;

            // Process refund for e-pay bookings if there's a refund amount
            // Process fund redistribution for e-pay bookings (refund + driver payout)
            $refundProcessed = false;
            if ($ride->payment_method === 'e-pay' && $booking->status === 'confirmed') {
                $this->processTimeBasedRefund($booking, $ride, $refundAmount, $seatsToCancel, $refundInfo);
                $refundProcessed = true;
            }

            if ($remainingSeats > 0) {
                // Update booking with remaining seats
                $booking->seats = $remainingSeats;
                $booking->save();
                $message = "Successfully cancelled {$seatsToCancel} seat(s). You still have {$remainingSeats} seat(s) booked.";
            } else {
                // Cancel entire booking
                $booking->status = Booking::CANCELLED;
                $booking->cancelled_at = now();
                $booking->save();
                $message = "Successfully cancelled all seats. Your booking has been cancelled.";
            }

            // Return seats to ride availability
            $ride->increment('available_seats', $seatsToCancel);

            // Update ride status if it was full
            if ($ride->status === 'full') {
                $ride->status = 'active';
                $ride->save();
            }

            DB::commit();

            // Send notifications
            $this->sendTimeBasedCancellationNotifications($booking, $ride, $seatsToCancel, $refundAmount, $refundProcessed, $refundInfo);

            Log::info('Time-based cancellation successful', [
                'booking_id' => $booking->id,
                'ride_id' => $ride->id,
                'passenger_id' => $user->id,
                'seats_cancelled' => $seatsToCancel,
                'time_elapsed_percentage' => $refundInfo['time_elapsed_percentage'],
                'refund_percentage' => $refundInfo['refund_percentage'],
                'refund_amount' => $refundAmount,
                'non_refundable_amount' => $nonRefundableAmount,
                'policy_tier' => $refundInfo['policy_tier']
            ]);

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => [
                    'booking_id' => $booking->id,
                    'seats_cancelled' => $seatsToCancel,
                    'remaining_seats' => $remainingSeats,
                    'refund_policy' => [
                        'time_elapsed_percentage' => round($refundInfo['time_elapsed_percentage'], 2),
                        'refund_percentage' => $refundInfo['refund_percentage'],
                        'policy_tier' => $refundInfo['policy_tier'],
                        'total_seat_price' => $totalFullPrice,
                        'refund_amount' => $refundAmount,
                        'non_refundable_amount' => $nonRefundableAmount,
                        'refund_processed' => $refundProcessed
                    ],
                    'booking_status' => $booking->status,
                ]
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Time-based cancellation failed', [
                'booking_id' => $bookingId,
                'passenger_id' => $user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Cancellation failed: ' . $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Process partial refund for e-pay bookings using SyCash wallet
     */
    /**
     * Process partial refund for e-pay bookings using primary admin wallet
     */
    /**
     * Process partial refund for e-pay bookings using primary admin wallet
     */
    private function processPartialRefund($booking, $ride, $refundAmount, $seatsCancelled) {
        // Get primary admin wallet (where the refunds come from)
        $primaryAdminConfig = AdminDashboardController::ADMIN_CONFIGS['primary'];
        $primaryAdminWallet = Wallet::where('phone_number', $primaryAdminConfig['phone'])
            ->lockForUpdate()
            ->firstOrFail();

        // Get passenger wallet
        $passengerWallet = Wallet::where('user_id', $booking->user_id)
            ->lockForUpdate()
            ->firstOrFail();

        // Verify primary admin has sufficient balance for refund
        if ($primaryAdminWallet->balance < $refundAmount) {
            throw new \Exception('Insufficient primary admin wallet balance for refund processing');
        }

        // Process refund from primary admin to passenger
        $adminPreviousBalance = $primaryAdminWallet->balance;
        $primaryAdminWallet->balance -= $refundAmount;
        $primaryAdminWallet->save();

        $passengerPreviousBalance = $passengerWallet->balance;
        $passengerWallet->balance += $refundAmount;
        $passengerWallet->save();

        // Create transaction records
        $transactionId = 'PARTIAL_REFUND_' . time() . '_' . Str::random(6);

        // Primary admin transaction (debit)
        WalletTransaction::create([
            'wallet_id' => $primaryAdminWallet->id,
            'user_id' => $primaryAdminWallet->user_id,
            'type' => 'partial_seat_refund',
            'amount' => -$refundAmount,
            'previous_balance' => $adminPreviousBalance,
            'new_balance' => $primaryAdminWallet->balance,
            'description' => "Partial refund for {$seatsCancelled} cancelled seat(s) to {$passengerWallet->user->first_name} {$passengerWallet->user->last_name}",
            'transaction_id' => 'PRIMARY_ADM_' . $transactionId,
            'status' => 'completed',
            'metadata' => [
                'booking_id' => $booking->id,
                'ride_id' => $ride->id,
                'passenger_id' => $booking->user_id,
                'passenger_name' => "{$passengerWallet->user->first_name} {$passengerWallet->user->last_name}",
                'seats_cancelled' => $seatsCancelled,
                'refund_per_seat' => $ride->price_per_seat,
                'pickup_address' => $ride->pickup_address,
                'destination_address' => $ride->destination_address,
                'refund_source' => 'primary_admin_wallet'
            ]
        ]);

        // Passenger transaction (credit)
        WalletTransaction::create([
            'wallet_id' => $passengerWallet->id,
            'user_id' => $passengerWallet->user_id,
            'type' => 'partial_seat_refund',
            'amount' => $refundAmount,
            'previous_balance' => $passengerPreviousBalance,
            'new_balance' => $passengerWallet->balance,
            'description' => "Refund for {$seatsCancelled} cancelled seat(s) from {$ride->pickup_address} to {$ride->destination_address}",
            'transaction_id' => 'PASSENGER_' . $transactionId,
            'status' => 'completed',
            'metadata' => [
                'booking_id' => $booking->id,
                'ride_id' => $ride->id,
                'seats_cancelled' => $seatsCancelled,
                'refund_per_seat' => $ride->price_per_seat,
                'pickup_address' => $ride->pickup_address,
                'destination_address' => $ride->destination_address,
                'refund_source' => 'primary_admin_wallet'
            ]
        ]);

        Log::info('Partial refund processed from primary admin wallet', [
            'booking_id' => $booking->id,
            'passenger_wallet_id' => $passengerWallet->id,
            'admin_wallet_id' => $primaryAdminWallet->id,
            'refund_amount' => $refundAmount,
            'seats_cancelled' => $seatsCancelled,
            'transaction_id' => $transactionId,
            'admin_balance_after' => $primaryAdminWallet->balance,
            'passenger_balance_after' => $passengerWallet->balance
        ]);
    }
    private function sendPartialCancellationNotifications($booking, $ride, $seatsCancelled, $refundAmount, $refundProcessed)
    {
        $remainingSeats = $booking->seats;
        $passenger = $booking->user;
        $driver = $ride->driver;

        // Notify passenger
        $passengerMessage = $remainingSeats > 0
            ? "You've cancelled {$seatsCancelled} seat(s). You still have {$remainingSeats} seat(s) booked."
            : "You've cancelled your booking completely.";

        if ($refundProcessed) {
            $passengerMessage .= " Refund of $" . number_format($refundAmount, 2) . " has been processed.";
        }

        $this->notificationService->createNotification(
            $passenger,
            'partial_seat_cancellation',
            'Seats Cancelled',
            $passengerMessage,
            [
                'booking_id' => $booking->id,
                'ride_id' => $ride->id,
                'seats_cancelled' => $seatsCancelled,
                'remaining_seats' => $remainingSeats,
                'refund_amount' => $refundProcessed ? $refundAmount : 0,
            ],
            'normal',
            'ride'
        );

        // Notify driver
        $driverMessage = $remainingSeats > 0
            ? "{$passenger->first_name} {$passenger->last_name} cancelled {$seatsCancelled} seat(s) but still has {$remainingSeats} seat(s) booked."
            : "{$passenger->first_name} {$passenger->last_name} cancelled their entire booking.";

        $this->notificationService->createNotification(
            $driver,
            'passenger_partial_cancellation',
            'Passenger Cancelled Seats',
            $driverMessage . " {$seatsCancelled} seat(s) are now available again.",
            [
                'booking_id' => $booking->id,
                'ride_id' => $ride->id,
                'passenger_id' => $passenger->id,
                'passenger_name' => "{$passenger->first_name} {$passenger->last_name}",
                'seats_cancelled' => $seatsCancelled,
                'remaining_seats' => $remainingSeats,
                'seats_now_available' => $ride->available_seats,
            ],
            'normal',
            'ride'
        );
    }
    public function getMyBookings(Request $request) {
        $user = $request->user();

        if (!$user->is_verified_passenger) {
            return response()->json([
                'success' => false,
                'message' => 'You must be verified as a passenger to view bookings.'
            ], 403);
        }

        try {
            // Get all bookings for this passenger, sorted by departure time (newest first)
            $bookings = Booking::with([
                'ride.driver.profile'
            ])
                ->where('user_id', $user->id)
                ->join('rides', 'bookings.ride_id', '=', 'rides.id')
                ->orderBy('rides.departure_time', 'desc')
                ->select('bookings.*')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $bookings->map(function ($booking) {
                    $ride = $booking->ride;
                    return [
                        'booking_id' => $booking->id,
                        'status' => $booking->status,
                        'seats' => $booking->seats,
                        'total_price' => $booking->seats * $ride->price_per_seat,
                        'booking_date' => $booking->created_at->toIso8601String(),

                        // Communication numbers - clearly labeled
                        'passenger_communication_number' => $booking->communication_number,
                        'driver_communication_number' => $ride->communication_number,

                        // Ride details
                        'ride_id' => $ride->id,
                        'pickup_address' => $ride->pickup_address,
                        'destination_address' => $ride->destination_address,
                        'departure_time' => $ride->departure_time->toIso8601String(),
                        'distance_km' => round($ride->distance / 1000, 1),
                        'duration_minutes' => round($ride->duration / 60),
                        'price_per_seat' => $ride->price_per_seat,
                        'payment_method' => $ride->payment_method,
                        'vehicle_type' => $ride->vehicle_type,
                        'ride_status' => $ride->status,

                        // Driver details - INCLUDING driver_id
                        'driver_id' => $ride->driver_id, // Added this line
                        'driver_name' => trim($ride->driver->first_name . ' ' . $ride->driver->last_name),
                        'driver_rating' => $ride->driver->driver_rating ?? 0,
                        'driver_avatar' => $ride->driver->profile && $ride->driver->profile->profile_photo
                            ? asset('storage/' . $ride->driver->profile->profile_photo)
                            : $ride->driver->avatar,
                    ];
                })
            ], 200);

        } catch (\Exception $e) {
            Log::error('Failed to fetch passenger bookings', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch bookings: ' . $e->getMessage(),
            ], 500);
        }
    }
    private function calculateRefundPercentage($departureTime, $bookingCreatedAt = null)
    {
        $now = now();
        $departure = Carbon::parse($departureTime);

        // If departure time has already passed, no refund
        if ($now->greaterThanOrEqualTo($departure)) {
            return [
                'refund_percentage' => 0,
                'time_elapsed_percentage' => 100,
                'policy_tier' => 'No refund - departure time passed'
            ];
        }

        // Use actual booking creation time if available, otherwise use current time
        $bookingTime = $bookingCreatedAt ? Carbon::parse($bookingCreatedAt) : $now;

        // Calculate total time from booking to departure
        $totalTimeFromBooking = $bookingTime->diffInMinutes($departure);

        // Calculate elapsed time since booking
        $timeElapsedSinceBooking = $bookingTime->diffInMinutes($now);

        // Calculate percentage of time elapsed
        $timeElapsedPercentage = $totalTimeFromBooking > 0
            ? min(100, ($timeElapsedSinceBooking / $totalTimeFromBooking) * 100)
            : 100;

        // Apply refund policy based on time elapsed percentage
        if ($timeElapsedPercentage <= 30) {
            return [
                'refund_percentage' => 100,
                'time_elapsed_percentage' => $timeElapsedPercentage,
                'policy_tier' => 'Full refund (0-30% time elapsed)',
                'total_minutes_from_booking' => $totalTimeFromBooking,
                'minutes_elapsed' => $timeElapsedSinceBooking
            ];
        } elseif ($timeElapsedPercentage <= 50) {
            return [
                'refund_percentage' => 70,
                'time_elapsed_percentage' => $timeElapsedPercentage,
                'policy_tier' => 'Partial refund (30-50% time elapsed)',
                'total_minutes_from_booking' => $totalTimeFromBooking,
                'minutes_elapsed' => $timeElapsedSinceBooking
            ];
        } elseif ($timeElapsedPercentage <= 70) {
            return [
                'refund_percentage' => 50,
                'time_elapsed_percentage' => $timeElapsedPercentage,
                'policy_tier' => 'Partial refund (50-70% time elapsed)',
                'total_minutes_from_booking' => $totalTimeFromBooking,
                'minutes_elapsed' => $timeElapsedSinceBooking
            ];
        } else {
            return [
                'refund_percentage' => 0,
                'time_elapsed_percentage' => $timeElapsedPercentage,
                'policy_tier' => 'No refund (70-100% time elapsed)',
                'total_minutes_from_booking' => $totalTimeFromBooking,
                'minutes_elapsed' => $timeElapsedSinceBooking
            ];
        }
    }
    private function processTimeBasedRefund($booking, $ride, $refundAmount, $seatsCancelled, $refundInfo) {
        // Get primary admin wallet (where passenger payments are held)
        $primaryAdminConfig = AdminDashboardController::ADMIN_CONFIGS['primary'];
        $primaryAdminWallet = Wallet::where('phone_number', $primaryAdminConfig['phone'])
            ->lockForUpdate()
            ->firstOrFail();

        // Get passenger wallet
        $passengerWallet = Wallet::where('user_id', $booking->user_id)
            ->lockForUpdate()
            ->firstOrFail();

        // Get driver wallet
        $driverWallet = Wallet::where('user_id', $ride->driver_id)
            ->lockForUpdate()
            ->firstOrFail();

        // Calculate amounts
        $totalPaid = $seatsCancelled * $ride->price_per_seat;
        $nonRefundableAmount = $totalPaid - $refundAmount;

        // Verify admin has sufficient balance for the total transaction
        if ($primaryAdminWallet->balance < $totalPaid) {
            throw new \Exception('Insufficient admin wallet balance for processing cancellation');
        }

        // Store initial balances
        $adminPreviousBalance = $primaryAdminWallet->balance;
        $passengerPreviousBalance = $passengerWallet->balance;
        $driverPreviousBalance = $driverWallet->balance;

        // Process the fund redistribution
        $primaryAdminWallet->balance -= $totalPaid; // Remove total amount from admin

        // Add refund to passenger if any
        if ($refundAmount > 0) {
            $passengerWallet->balance += $refundAmount;
        }

        // Add non-refundable amount to driver (ALWAYS if nonRefundableAmount > 0)
        if ($nonRefundableAmount > 0) {
            $driverWallet->balance += $nonRefundableAmount;
        }

        // Save all wallets
        $primaryAdminWallet->save();
        $passengerWallet->save();
        $driverWallet->save();

        // Create transaction records
        $transactionId = 'TIME_CANCEL_' . time() . '_' . Str::random(6);

        // 1. Admin wallet transaction (total deduction) - ALWAYS CREATE
        WalletTransaction::create([
            'wallet_id' => $primaryAdminWallet->id,
            'user_id' => $primaryAdminWallet->user_id,
            'type' => 'cancellation_processing',
            'amount' => -$totalPaid,
            'previous_balance' => $adminPreviousBalance,
            'new_balance' => $primaryAdminWallet->balance,
            'description' => "Processing cancellation: {$seatsCancelled} seat(s) - Refund: $" . number_format($refundAmount, 2) . ", Driver payout: $" . number_format($nonRefundableAmount, 2),
            'transaction_id' => 'ADMIN_' . $transactionId,
            'status' => 'completed',
            'metadata' => [
                'booking_id' => $booking->id,
                'ride_id' => $ride->id,
                'passenger_id' => $booking->user_id,
                'driver_id' => $ride->driver_id,
                'seats_cancelled' => $seatsCancelled,
                'total_paid' => $totalPaid,
                'refund_amount' => $refundAmount,
                'driver_payout' => $nonRefundableAmount,
                'refund_percentage' => $refundInfo['refund_percentage'],
                'time_elapsed_percentage' => $refundInfo['time_elapsed_percentage'],
                'policy_tier' => $refundInfo['policy_tier']
            ]
        ]);

        // 2. Passenger refund transaction (ONLY if refund > 0)
        if ($refundAmount > 0) {
            WalletTransaction::create([
                'wallet_id' => $passengerWallet->id,
                'user_id' => $passengerWallet->user_id,
                'type' => 'time_based_refund',
                'amount' => $refundAmount,
                'previous_balance' => $passengerPreviousBalance,
                'new_balance' => $passengerWallet->balance,
                'description' => "Refund ({$refundInfo['refund_percentage']}%) for {$seatsCancelled} cancelled seat(s) - {$refundInfo['policy_tier']}",
                'transaction_id' => 'REFUND_' . $transactionId,
                'status' => 'completed',
                'metadata' => [
                    'booking_id' => $booking->id,
                    'ride_id' => $ride->id,
                    'seats_cancelled' => $seatsCancelled,
                    'refund_percentage' => $refundInfo['refund_percentage'],
                    'time_elapsed_percentage' => $refundInfo['time_elapsed_percentage'],
                    'policy_tier' => $refundInfo['policy_tier'],
                    'pickup_address' => $ride->pickup_address,
                    'destination_address' => $ride->destination_address
                ]
            ]);
        }

        // 3. Driver payout transaction (ALWAYS CREATE if nonRefundableAmount > 0)
        // FIXED: This will now work for 0% refund cases (70%+ time elapsed)
        if ($nonRefundableAmount > 0) {
            WalletTransaction::create([
                'wallet_id' => $driverWallet->id,
                'user_id' => $driverWallet->user_id,
                'type' => 'cancellation_fee_earnings',
                'amount' => $nonRefundableAmount,
                'previous_balance' => $driverPreviousBalance,
                'new_balance' => $driverWallet->balance,
                'description' => "Cancellation earnings - {$seatsCancelled} seat(s) ({$refundInfo['policy_tier']})",
                'transaction_id' => 'DRIVER_' . $transactionId,
                'status' => 'completed',
                'metadata' => [
                    'booking_id' => $booking->id,
                    'ride_id' => $ride->id,
                    'passenger_id' => $booking->user_id,
                    'passenger_name' => "{$passengerWallet->user->first_name} {$passengerWallet->user->last_name}",
                    'seats_cancelled' => $seatsCancelled,
                    'total_paid' => $totalPaid,
                    'refund_percentage' => $refundInfo['refund_percentage'],
                    'time_elapsed_percentage' => $refundInfo['time_elapsed_percentage'],
                    'policy_tier' => $refundInfo['policy_tier'],
                    'pickup_address' => $ride->pickup_address,
                    'destination_address' => $ride->destination_address
                ]
            ]);
        }

        // 4. SPECIAL CASE: If no refund (0%) but total was paid (100% to driver)
        // Create a specific transaction for the passenger showing 0 refund
        if ($refundAmount == 0 && $totalPaid > 0) {
            WalletTransaction::create([
                'wallet_id' => $passengerWallet->id,
                'user_id' => $passengerWallet->user_id,
                'type' => 'cancellation_no_refund',
                'amount' => 0, // No money movement, just for record
                'previous_balance' => $passengerPreviousBalance,
                'new_balance' => $passengerWallet->balance, // Should be same as previous
                'description' => "No refund due to late cancellation - {$seatsCancelled} seat(s) forfeited ({$refundInfo['policy_tier']})",
                'transaction_id' => 'NO_REFUND_' . $transactionId,
                'status' => 'completed',
                'metadata' => [
                    'booking_id' => $booking->id,
                    'ride_id' => $ride->id,
                    'seats_cancelled' => $seatsCancelled,
                    'refund_percentage' => $refundInfo['refund_percentage'],
                    'time_elapsed_percentage' => $refundInfo['time_elapsed_percentage'],
                    'policy_tier' => $refundInfo['policy_tier'],
                    'forfeited_amount' => $totalPaid,
                    'pickup_address' => $ride->pickup_address,
                    'destination_address' => $ride->destination_address
                ]
            ]);
        }

        Log::info('Time-based cancellation fund redistribution completed', [
            'booking_id' => $booking->id,
            'transaction_id' => $transactionId,
            'total_paid' => $totalPaid,
            'refund_amount' => $refundAmount,
            'driver_payout' => $nonRefundableAmount,
            'admin_balance_before' => $adminPreviousBalance,
            'admin_balance_after' => $primaryAdminWallet->balance,
            'passenger_balance_before' => $passengerPreviousBalance,
            'passenger_balance_after' => $passengerWallet->balance,
            'driver_balance_before' => $driverPreviousBalance,
            'driver_balance_after' => $driverWallet->balance,
            'refund_processed' => $refundAmount > 0,
            'driver_payout_processed' => $nonRefundableAmount > 0,
            'no_refund_recorded' => $refundAmount == 0 && $totalPaid > 0
        ]);
    }
    private function sendTimeBasedCancellationNotifications($booking, $ride, $seatsCancelled, $refundAmount, $refundProcessed, $refundInfo) {
        $remainingSeats = $booking->seats;
        $passenger = $booking->user;
        $driver = $ride->driver;
        $totalPrice = $seatsCancelled * $ride->price_per_seat;
        $nonRefundableAmount = $totalPrice - $refundAmount;

        // Notify passenger with detailed refund information
        $passengerMessage = $remainingSeats > 0
            ? "You've cancelled {$seatsCancelled} seat(s). You still have {$remainingSeats} seat(s) booked."
            : "You've cancelled your booking completely.";

        if ($refundProcessed && $refundAmount > 0) {
            $passengerMessage .= " Time elapsed: " . round($refundInfo['time_elapsed_percentage'], 1) . "%. ";
            $passengerMessage .= "Refund: $" . number_format($refundAmount, 2) . " ({$refundInfo['refund_percentage']}%). ";

            if ($nonRefundableAmount > 0) {
                $passengerMessage .= "Non-refundable amount ($" . number_format($nonRefundableAmount, 2) . ") has been transferred to the driver as per cancellation policy.";
            }
        } elseif ($refundInfo['refund_percentage'] == 0) {
            $passengerMessage .= " Time elapsed: " . round($refundInfo['time_elapsed_percentage'], 1) . "%. ";
            $passengerMessage .= "No refund available due to cancellation policy. Full amount ($" . number_format($totalPrice, 2) . ") has been transferred to the driver.";
        }

        $this->notificationService->createNotification(
            $passenger,
            'time_based_cancellation',
            'Seats Cancelled - Payment Processed',
            $passengerMessage,
            [
                'booking_id' => $booking->id,
                'ride_id' => $ride->id,
                'seats_cancelled' => $seatsCancelled,
                'time_elapsed_percentage' => $refundInfo['time_elapsed_percentage'],
                'refund_percentage' => $refundInfo['refund_percentage'],
                'refund_amount' => $refundAmount,
                'non_refundable_amount' => $nonRefundableAmount,
                'policy_tier' => $refundInfo['policy_tier']
            ],
            'normal',
            'ride'
        );

        // Notify driver about cancellation and payment
        $driverMessage = $remainingSeats > 0
            ? "{$passenger->first_name} {$passenger->last_name} cancelled {$seatsCancelled} seat(s) but still has {$remainingSeats} seat(s) booked."
            : "{$passenger->first_name} {$passenger->last_name} cancelled their entire booking.";

        $driverMessage .= " {$seatsCancelled} seat(s) are now available again.";

        if ($nonRefundableAmount > 0) {
            $driverMessage .= " You've received $" . number_format($nonRefundableAmount, 2) . " as cancellation fee based on the time-based policy ({$refundInfo['policy_tier']}).";
        } else {
            $driverMessage .= " Full refund was issued to passenger due to early cancellation.";
        }

        $this->notificationService->createNotification(
            $driver,
            'passenger_cancellation_earnings',
            'Passenger Cancelled - Payment Received',
            $driverMessage,
            [
                'booking_id' => $booking->id,
                'ride_id' => $ride->id,
                'passenger_id' => $passenger->id,
                'seats_cancelled' => $seatsCancelled,
                'earnings_from_cancellation' => $nonRefundableAmount,
                'time_elapsed_percentage' => $refundInfo['time_elapsed_percentage'],
                'refund_percentage' => $refundInfo['refund_percentage']
            ],
            'normal',
            'ride'
        );
    }
    /**
     * Parse and validate departure time in Syria timezone
     *
     * @param string $timeString
     * @return Carbon
     * @throws \Exception
     */
    private function parseAndValidateDepartureTime($timeString)
    {
        try {
            // Parse the input time in Syria timezone
            $departureTime = Carbon::parse($timeString, 'Asia/Damascus');
            $now = Carbon::now('Asia/Damascus');

            // Add 5-minute buffer to prevent immediate bookings
            $minimumTime = $now->addMinutes(5);

            if ($departureTime->lte($minimumTime)) {
                throw new \Exception(
                    'Departure time must be at least 5 minutes in the future. ' .
                    'Current Syria time: ' . $now->format('Y-m-d H:i:s') . '. ' .
                    'Your input: ' . $departureTime->format('Y-m-d H:i:s')
                );
            }

            return $departureTime;

        } catch (\Carbon\Exceptions\InvalidFormatException $e) {
            throw new \Exception('Invalid date format. Please use ISO 8601 format (e.g., 2025-09-03T14:30:00+03:00)');
        }
    }

    /**
     * Get current Syria time for logging/debugging
     *
     * @return Carbon
     */
    private function getSyriaTime()
    {
        return Carbon::now('Asia/Damascus');
    }

    /**
     * Format time for consistent API responses
     *
     * @param Carbon $time
     * @return string
     */
    private function formatTimeForResponse($time)
    {
        return $time->setTimezone('Asia/Damascus')->toISOString();
    }
}
