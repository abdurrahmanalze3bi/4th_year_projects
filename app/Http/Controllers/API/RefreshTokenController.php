<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\JwtService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class RefreshTokenController extends Controller
{
    private JwtService $jwtService;

    public function __construct(JwtService $jwtService)
    {
        $this->jwtService = $jwtService;
    }

    /**
     * Refresh access token using refresh token
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function __invoke(Request $request)
    {
        // Validate request
        $validator = Validator::make($request->all(), [
            'refresh_token' => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // Refresh token
        $tokens = $this->jwtService->refreshAccessToken($request->refresh_token);

        if (!$tokens) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid or expired refresh token',
                'code' => 'REFRESH_TOKEN_INVALID'
            ], 401);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Token refreshed successfully',
            'tokens' => $tokens
        ], 200);
    }
}
