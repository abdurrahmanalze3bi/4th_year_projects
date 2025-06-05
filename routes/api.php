<?php
namespace App\Http\Controllers\API\NotificationController;
use App\Http\Controllers\API\Auth\GoogleController;
use App\Http\Controllers\API\ChatController;
use App\Http\Controllers\API\DocumentController;
use App\Http\Controllers\API\NotificationController;

use App\Http\Controllers\API\RideController;
use App\Http\Controllers\API\VerificationController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\SignupController;
use App\Http\Controllers\API\LoginController;
use App\Http\Controllers\API\LogoutController;
use App\Http\Controllers\API\ForgotPasswordController;
use App\Http\Controllers\API\ResetPasswordController;
use App\Http\Controllers\API\ProfileController;
/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/// routes/api.php

// Public routes…
Route::post('/signup',    [SignupController::class, 'register']);
Route::post('/login',     LoginController::class);
// …
// Add this to your public routes
Route::post('/reset-password', \App\Http\Controllers\API\ResetPasswordController::class);
Route::post('/forgot-password', \App\Http\Controllers\API\ForgotPasswordController::class);
// Authenticated routes
Route::middleware('auth:sanctum')->group(function () {
    // Protected routes
    Route::get('/user', fn(Request $r) => $r->user());
    Route::post('/logout', \App\Http\Controllers\API\LogoutController::class);




    // Profile routes
    Route::prefix('profile')->group(function () {
        Route::post('/documents', [DocumentController::class, 'store']);
        Route::post('/verify/passenger', [VerificationController::class, 'verifyPassenger']);
        Route::post('/verify/driver', [VerificationController::class, 'verifyDriver']);
        Route::get('/', [\App\Http\Controllers\API\ProfileController::class, 'show']);
        Route::get('/profile/verify/status/{userId}', [VerificationController::class, 'status']);
        Route::post('/', [\App\Http\Controllers\API\ProfileController::class, 'update']);
        Route::post('/{userId}/comments', [\App\Http\Controllers\API\ProfileController::class, 'comment']);
        Route::post('/{userId}/rate', [\App\Http\Controllers\API\ProfileController::class, 'rateUser']); // New rating endpoint
    });

    Route::post('/rides', [RideController::class, 'createRide']);
    Route::get('/rides', [RideController::class, 'getRides']);
    Route::get('/rides/{rideId}', [RideController::class, 'getRideDetails']);
    Route::post('/rides/{rideId}/book', [RideController::class, 'bookRide']);
    Route::get('/autocomplete', [RideController::class, 'autocomplete']);
    Route::post('/rides/search', [RideController::class, 'searchRides']);
    Route::patch('/rides/{ride}/cancel', [RideController::class, 'cancelRide']);


    Route::prefix('chat')->group(function () {
        Route::get('/conversations', [ChatController::class, 'getConversations']);
        Route::post('/conversations', [ChatController::class, 'startConversation']);
        Route::get('/conversations/{conversationId}/messages', [ChatController::class, 'getMessages']);
        Route::post('/conversations/{conversationId}/messages', [ChatController::class, 'sendMessage']);
        Route::delete('/messages/{messageId}', [ChatController::class, 'deleteMessage']);




    });
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

// other auth'd routes…
Route::get('/user',    fn(Request $r) => $r->user());
