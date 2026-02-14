<?php

namespace App\Services;

use App\Models\User;
use App\Models\RefreshToken;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Carbon\Carbon;

class JwtService
{
    /**
     * Generate access and refresh tokens for a user
     *
     * @param User $user
     * @return array
     */
    public function generateTokenPair(User $user): array
    {
        // Generate access token (short-lived: 15 minutes)
        $accessToken = $this->generateAccessToken($user);

        // Generate refresh token (long-lived: 7 days)
        $refreshToken = $this->generateRefreshToken($user);

        return [
            'access_token' => $accessToken['token'],
            'access_token_expires_at' => $accessToken['expires_at'],
            'refresh_token' => $refreshToken['token'],
            'refresh_token_expires_at' => $refreshToken['expires_at'],
            'token_type' => 'Bearer'
        ];
    }

    /**
     * Generate access token
     *
     * @param User $user
     * @return array
     */
    private function generateAccessToken(User $user): array
    {
        $expiresIn = config('jwt.ttl', 15); // 15 minutes default
        $expiresAt = Carbon::now()->addMinutes($expiresIn);

        $payload = [
            'iss' => config('app.url'),
            'sub' => $user->id,
            'iat' => Carbon::now()->timestamp,
            'exp' => $expiresAt->timestamp,
            'jti' => Str::uuid()->toString(),
            'type' => 'access'
        ];

        $token = $this->encodeToken($payload);

        return [
            'token' => $token,
            'expires_at' => $expiresAt->toDateTimeString(),
            'expires_in' => $expiresIn * 60 // in seconds
        ];
    }

    /**
     * Generate and store refresh token
     *
     * @param User $user
     * @return array
     */
    private function generateRefreshToken(User $user): array
    {
        $expiresIn = config('jwt.refresh_ttl', 10080); // 7 days in minutes
        $expiresAt = Carbon::now()->addMinutes($expiresIn);

        // Generate unique refresh token
        $tokenString = Str::random(64);
        $hashedToken = hash('sha256', $tokenString);

        // Store in database
        RefreshToken::create([
            'user_id' => $user->id,
            'token' => $hashedToken,
            'expires_at' => $expiresAt,
            'user_agent' => request()->userAgent(),
            'ip_address' => request()->ip()
        ]);

        return [
            'token' => $tokenString,
            'expires_at' => $expiresAt->toDateTimeString(),
            'expires_in' => $expiresIn * 60 // in seconds
        ];
    }

    /**
     * Encode JWT token
     *
     * @param array $payload
     * @return string
     */
    private function encodeToken(array $payload): string
    {
        $header = [
            'typ' => 'JWT',
            'alg' => config('jwt.algo', 'HS256')
        ];

        $headerEncoded = $this->base64UrlEncode(json_encode($header));
        $payloadEncoded = $this->base64UrlEncode(json_encode($payload));

        $signature = $this->generateSignature($headerEncoded, $payloadEncoded);

        return "{$headerEncoded}.{$payloadEncoded}.{$signature}";
    }

    /**
     * Decode and validate JWT token
     *
     * @param string $token
     * @return array|null
     */
    public function decodeToken(string $token): ?array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            return null;
        }

        [$headerEncoded, $payloadEncoded, $signature] = $parts;

        // Verify signature
        $expectedSignature = $this->generateSignature($headerEncoded, $payloadEncoded);
        if (!hash_equals($expectedSignature, $signature)) {
            return null;
        }

        // Decode payload
        $payload = json_decode($this->base64UrlDecode($payloadEncoded), true);

        if (!$payload) {
            return null;
        }

        // Check expiration
        if (isset($payload['exp']) && Carbon::now()->timestamp > $payload['exp']) {
            return null;
        }

        return $payload;
    }

    /**
     * Refresh access token using refresh token
     *
     * @param string $refreshToken
     * @return array|null
     */
    public function refreshAccessToken(string $refreshToken): ?array
    {
        $hashedToken = hash('sha256', $refreshToken);

        // Find refresh token in database
        $storedToken = RefreshToken::where('token', $hashedToken)
            ->where('expires_at', '>', Carbon::now())
            ->where('revoked', false)
            ->first();

        if (!$storedToken) {
            return null;
        }

        // Get user
        $user = User::find($storedToken->user_id);
        if (!$user) {
            return null;
        }

        // Refresh token rotation: revoke old token and generate new pair
        $storedToken->update(['revoked' => true]);

        return $this->generateTokenPair($user);
    }

    /**
     * Revoke all user's refresh tokens
     *
     * @param int $userId
     * @return void
     */
    public function revokeAllTokens(int $userId): void
    {
        RefreshToken::where('user_id', $userId)
            ->where('revoked', false)
            ->update(['revoked' => true]);
    }

    /**
     * Clean up expired tokens
     *
     * @return int Number of deleted tokens
     */
    public function cleanupExpiredTokens(): int
    {
        return RefreshToken::where('expires_at', '<', Carbon::now())
            ->orWhere('revoked', true)
            ->delete();
    }

    /**
     * Generate signature for JWT
     *
     * @param string $header
     * @param string $payload
     * @return string
     */
    private function generateSignature(string $header, string $payload): string
    {
        $secret = config('jwt.secret');
        $algo = strtolower(config('jwt.algo', 'HS256'));

        $algoMap = [
            'hs256' => 'sha256',
            'hs384' => 'sha384',
            'hs512' => 'sha512'
        ];

        $hashAlgo = $algoMap[$algo] ?? 'sha256';
        $signature = hash_hmac($hashAlgo, "{$header}.{$payload}", $secret, true);

        return $this->base64UrlEncode($signature);
    }

    /**
     * Base64 URL encode
     *
     * @param string $data
     * @return string
     */
    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Base64 URL decode
     *
     * @param string $data
     * @return string
     */
    private function base64UrlDecode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/'));
    }
}
