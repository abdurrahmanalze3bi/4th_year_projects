<?php

use App\Http\Controllers\API\Auth\GoogleController;
use App\Http\Controllers\API\ResetPasswordController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', function () {
    return view('welcome');
});

Route::prefix('auth/google')->group(function () {
    Route::get('/redirect', [GoogleController::class, 'redirect']);
    Route::get('/callback', [GoogleController::class, 'callback']);
});
