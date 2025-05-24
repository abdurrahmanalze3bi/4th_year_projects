<?php

use App\Http\Controllers\API\NotificationController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\Auth\GoogleController;

Route::get('/', function () {
    return view('welcome');
});
Route::middleware('auth')->group(function () {
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('/notifications/{id}/read', [NotificationController::class, 'markAsRead'])->name('notifications.read');
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllAsRead'])->name('notifications.readAll');
});

// Google OAuth
Route::prefix('auth/google')->group(function () {
    Route::get('/redirect', [GoogleController::class, 'redirect']);
    Route::get('/callback', [GoogleController::class, 'callback']);
});
Route::get('/reset-password', function (Request $request) {
    $token = $request->query('token');
    $email = $request->query('email');

    if (!$token || !$email) {
        return response()->view('errors.invalid-reset-link', [
            'message' => 'Invalid password reset link'
        ], 400);
    }

    return view('auth.reset-password', [
        'token' => $token,
        'email' => urldecode($email)
    ]);
})->name('password.reset');
Route::get('/env-test', function() {
    return [
        'env_key' => env('OPENROUTE_API_KEY'),
        'config_key' => config('services.openroute.api_key')
    ];

});
