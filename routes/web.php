<?php

use App\Http\Controllers\API\NotificationController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\Auth\GoogleController;
use App\Http\Controllers\API\AdminDashboardController;
use Illuminate\Support\Facades\Session;

Route::prefix('admin')->group(function () {
    // Login/Logout
    Route::get('/login', [AdminDashboardController::class, 'showLoginForm'])->name('admin.login');
    Route::post('/login', [AdminDashboardController::class, 'login']);
    Route::get('/logout', [AdminDashboardController::class, 'logout'])->name('admin.logout');

    // Dashboard
    Route::get('/dashboard', [AdminDashboardController::class, 'showDashboard'])->name('admin.dashboard');
// Add this to the admin group
    Route::get('/report', [AdminDashboardController::class, 'showReport'])->name('admin.report');
    // Admin Info & Wallets
    Route::get('/info', [AdminDashboardController::class, 'getAdminInfo'])->name('admin.info');
    Route::get('/wallet', [AdminDashboardController::class, 'getAdminWallet'])->name('admin.wallet');
    Route::get('/wallets/admins', [AdminDashboardController::class, 'getAdminWallets'])->name('admin.wallets.admins');

    // Wallets
    Route::get('/wallets', [AdminDashboardController::class, 'showWallets'])->name('admin.wallets');

    // Wallet Transactions
    Route::get('/wallet/{wallet_id}/transactions', [AdminDashboardController::class, 'showWalletTransactions'])
        ->name('admin.wallet.transactions');

    // Charge Wallet
    Route::get('/wallet/charge', [AdminDashboardController::class, 'showChargeForm'])->name('admin.charge.form');
    Route::post('/wallet/charge', [AdminDashboardController::class, 'chargeWallet'])->name('admin.charge.submit');
});

// Add to routes/web.php
// Add this above admin routes
Route::get('/session-debug', function() {
    return response()->json([
        'session' => session()->all(),
        'cookies' => request()->cookies->all(),
        'admin_logged_in' => session('admin_logged_in', false),
        'session_id' => session()->getId()
    ]);
});

// Temporary API test route
Route::post('/admin/api-test', function(Request $request) {
    if (!Session::get('admin_logged_in')) {
        return response()->json(['error' => 'Unauthorized'], 401);
    }
    return response()->json([
        'success' => true,
        'message' => 'API test successful',
        'session_data' => Session::all()
    ]);
});

Route::get('/', function () {
    return view('welcome');
});

Route::get('/test-db', function () {
    try {
        DB::connection()->getPdo();
        return response()->json(['status' => 'Database connected successfully!']);
    } catch (\Exception $e) {
        return response()->json(['error' => 'Database connection failed: ' . $e->getMessage()]);
    }
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
