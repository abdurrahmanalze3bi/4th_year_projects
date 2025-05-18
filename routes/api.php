<?php

use App\Http\Controllers\API\Auth\GoogleController;
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
        Route::get('/', [\App\Http\Controllers\API\ProfileController::class, 'show']);
        Route::post('/', [\App\Http\Controllers\API\ProfileController::class, 'update']);
        Route::post('/{userId}/comments', [\App\Http\Controllers\API\ProfileController::class, 'comment']);
    });
});

    // other auth’d routes…
    Route::get('/user',    fn(Request $r) => $r->user());

