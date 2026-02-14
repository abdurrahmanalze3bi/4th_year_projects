<?php

namespace App\Http\Middleware;

use App\Services\JwtService;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class JwtAuthMiddleware
{
    protected JwtService $jwtService;

    public function __construct(JwtService $jwtService)
    {
        $this->jwtService = $jwtService;
    }

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Get token from Authorization header
        $token = $this->extractToken($request);

        if (!$token) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthenticated',
                'code' => 'TOKEN_MISSING'
            ], 401);
        }

        // Decode and validate token
        $payload = $this->jwtService->decodeToken($token);

        if (!$payload) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid or expired token',
                'code' => 'TOKEN_INVALID'
            ], 401);
        }

        // Verify token type
        if (($payload['type'] ?? null) !== 'access') {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid token type',
                'code' => 'TOKEN_TYPE_INVALID'
            ], 401);
        }

        // Get user
        $user = User::find($payload['sub']);

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'User not found',
                'code' => 'USER_NOT_FOUND'
            ], 401);
        }

        // Check if user is active
        if ($user->status == 0) {
            return response()->json([
                'status' => 'error',
                'message' => 'User account is inactive',
                'code' => 'USER_INACTIVE'
            ], 401);
        }

        // Attach user to request
        $request->setUserResolver(function () use ($user) {
            return $user;
        });

        return $next($request);
    }

    /**
     * Extract token from request
     *
     * @param Request $request
     * @return string|null
     */
    private function extractToken(Request $request): ?string
    {
        $header = $request->header('Authorization');

        if (!$header || !str_starts_with($header, 'Bearer ')) {
            return null;
        }

        return substr($header, 7);
    }
}
