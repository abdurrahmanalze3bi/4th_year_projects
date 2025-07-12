<?php

use App\Http\Controllers\API\Auth\GoogleController;
use App\Http\Controllers\API\ChatController;
use App\Http\Controllers\API\DocumentController;
use App\Http\Controllers\API\NotificationController;
use App\Http\Controllers\API\OtpController;
use App\Http\Controllers\API\RideController;
use App\Http\Controllers\API\VerificationController;
use App\Http\Controllers\API\SignupController;
use App\Http\Controllers\API\LoginController;
use App\Http\Controllers\API\LogoutController;
use App\Http\Controllers\API\ForgotPasswordController;
use App\Http\Controllers\API\ResetPasswordController;
use App\Http\Controllers\API\ProfileController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Database connection test route
Route::get('test-db', function() {
    try {
        DB::connection()->getPdo();
        $tables = DB::select('SHOW TABLES');
        return response()->json([
            'message' => 'Database connection successful',
            'database' => config('database.connections.mysql.database'),
            'host' => config('database.connections.mysql.host'),
            'tables_count' => count($tables)
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'error' => 'Database connection failed: ' . $e->getMessage(),
            'config' => [
                'host' => config('database.connections.mysql.host'),
                'database' => config('database.connections.mysql.database'),
                'port' => config('database.connections.mysql.port')
            ]
        ], 500);
    }
});

// Test route for basic API functionality
Route::get('/test', function() {
    return response()->json(['message' => 'API is working!', 'timestamp' => now()]);
});

// OTP routes (public)
Route::post('/otp/send', [OtpController::class, 'sendOtp']);
Route::post('/otp/verify', [OtpController::class, 'verifyOtp']);

// Authentication routes (public)
Route::post('/signup', [SignupController::class, 'register']);
Route::post('/login', [LoginController::class, '__invoke']);
Route::post('/forgot-password', [ForgotPasswordController::class, '__invoke']);
Route::post('/reset-password', [ResetPasswordController::class, '__invoke']);
// Authenticated routes
Route::middleware('auth:sanctum')->group(function () {
    // User info
    Route::get('/user', fn(Request $r) => $r->user());
    Route::post('/logout', [LogoutController::class, '__invoke']);

    // Profile routes
    Route::prefix('profile')->group(function () {
        Route::get('/{userId}', [ProfileController::class, 'show']);
        Route::post('/', [ProfileController::class, 'update']);
        Route::post('/documents', [DocumentController::class, 'store']);
        Route::post('/verify/passenger', [VerificationController::class, 'verifyPassenger']);
        Route::post('/verify/driver', [VerificationController::class, 'verifyDriver']);
        Route::get('/verify/status/{userId}', [VerificationController::class, 'status']);
        Route::post('/{userId}/comments', [ProfileController::class, 'comment']);
        Route::post('/{userId}/rate', [ProfileController::class, 'rateUser']);
    });

    // Ride routes
    Route::prefix('rides')->group(function () {
        Route::post('/', [RideController::class, 'createRide']);
        Route::get('/', [RideController::class, 'getRides']);
        Route::get('/{rideId}', [RideController::class, 'getRideDetails']);
        Route::post('/{rideId}/book', [RideController::class, 'bookRide']);
        Route::patch('/{ride}/cancel', [RideController::class, 'cancelRide']);
        Route::post('/search', [RideController::class, 'searchRides']);
        Route::post('/route-options', [RideController::class, 'getRouteOptions']);
        Route::post('/create-with-route', [RideController::class, 'createRideWithRoute']);
    });

    // Autocomplete route (separate from rides)
    Route::get('/autocomplete', [RideController::class, 'autocomplete']);

    // Chat routes
    Route::prefix('chat')->group(function () {
        Route::get('/conversations', [ChatController::class, 'getConversations']);
        Route::post('/conversations', [ChatController::class, 'startConversation']);
        Route::get('/conversations/{conversationId}/messages', [ChatController::class, 'getMessages']);
        Route::post('/conversations/{conversationId}/messages', [ChatController::class, 'sendMessage']);
        Route::delete('/messages/{messageId}', [ChatController::class, 'deleteMessage']);
    });

    // Notification routes
    Route::prefix('notifications')->group(function () {
        Route::get('/', [NotificationController::class, 'index']);
        Route::get('/unread-count', [NotificationController::class, 'getUnreadCount']);
        Route::post('/{id}/read', [NotificationController::class, 'markAsRead']);
        Route::post('/read-all', [NotificationController::class, 'markAllAsRead']);
        Route::delete('/{id}', [NotificationController::class, 'destroy']);
        Route::get('/categories', [NotificationController::class, 'getCategories']);
        Route::post('/bulk-action', [NotificationController::class, 'bulkAction']);
    });
});
