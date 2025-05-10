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
        // Revoke current token
        $request->user()->currentAccessToken()->delete();

        // Update user status to 0
        $this->userRepository->updateUserStatus($request->user()->id, 0);

        return response()->json([
            'message' => 'Successfully logged out',
            'status' => 0
        ]);
    }
}
