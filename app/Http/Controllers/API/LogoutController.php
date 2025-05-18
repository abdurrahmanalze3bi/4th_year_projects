<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Interfaces\UserRepositoryInterface;

class LogoutController extends Controller
{
    private $userRepository;

    public function __construct(UserRepositoryInterface $userRepository) {
        $this->userRepository = $userRepository;
    }

    public function __invoke(Request $request) {
        // Get authenticated user
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'message' => 'Unauthenticated'
            ], 401);
        }

        // Revoke ONLY the current token
        $currentToken = $user->currentAccessToken();
        if ($currentToken) {
            $currentToken->delete();
        }

        // Update user status
        $this->userRepository->updateUserStatus($user->id, 0);

        return response()->json([
            'message' => 'Successfully logged out',
            'status' => 0
        ]);
    }
}
