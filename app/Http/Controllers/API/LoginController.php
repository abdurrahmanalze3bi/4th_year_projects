<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller; // Add this line
use App\Interfaces\UserRepositoryInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class LoginController extends Controller
{
    private $userRepository;

    public function __construct(UserRepositoryInterface $userRepository) {
        $this->userRepository = $userRepository;
    }

    // app/Http/Controllers/API/LoginController.php
    public function __invoke(Request $request)
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required'
        ]);

        if (!Auth::attempt($credentials)) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        // Update status to 1 (active)
        $user = Auth::user();
        $user->update(['status' => 1]);

        return response()->json([
            'access_token' => $user->createToken('auth-token')->plainTextToken,
            'token_type' => 'Bearer'
        ]);
    }
}
