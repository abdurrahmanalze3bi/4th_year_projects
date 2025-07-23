<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Interfaces\UserRepositoryInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LoginController extends Controller
{
    private $userRepository;

    public function __construct(UserRepositoryInterface $userRepository) {
        $this->userRepository = $userRepository;
    }

    public function __invoke(Request $request)
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required'
        ]);

        if (!Auth::attempt($credentials)) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        // Update user status to active
        $user = Auth::user();
        $user->update(['status' => 1]);

        return response()->json([
            'user' => $user->only([
                'id',
                'first_name',
                'last_name',
                'email',
                'gender',
                'address',
                'status',
                'created_at'
            ]),
            'access_token' => $user->createToken('auth-token')->plainTextToken,
            'token_type' => 'Bearer'
        ]);
    }
}
