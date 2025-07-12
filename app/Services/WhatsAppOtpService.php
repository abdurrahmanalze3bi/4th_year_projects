<?php

namespace App\Services;

use App\Interfaces\OtpRepositoryInterface;
use App\Models\Otp;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;

class WhatsAppOtpService
{
    protected $otpRepository;
    protected $client;
    protected $apiKey;

    public function __construct(OtpRepositoryInterface $otpRepository)
    {
        $this->otpRepository = $otpRepository;
        $this->client = new Client();
        $this->apiKey = env('CALLMEBOT_API_KEY');
    }

    /**
     * Send OTP via WhatsApp
     */
    public function sendOtp(string $phoneNumber, string $type = 'registration'): array
    {
        try {
            // Validate Syrian phone number
            $validatedPhone = $this->validateSyrianPhone($phoneNumber);

            // Check rate limiting
            if (!$this->canSendOtp($validatedPhone)) {
                return [
                    'success' => false,
                    'message' => 'Too many OTP requests. Please try again later.'
                ];
            }

            // Delete existing OTPs for this phone
            $this->otpRepository->deleteByPhone($validatedPhone);

            // Generate OTP
            $otpCode = Otp::generateCode();

            // Create OTP record
            $otp = $this->otpRepository->create([
                'phone_number' => $validatedPhone,
                'otp_code' => $otpCode,
                'type' => $type,
                'expires_at' => Carbon::now()->addMinutes(5),
                'is_verified' => false,
                'attempts' => 0
            ]);

            // Always try to send if API key exists
            if (!empty($this->apiKey)) {
                $sent = $this->sendViaCallMeBot($validatedPhone, $otpCode);

                if (!$sent) {
                    Log::error("Failed to send OTP to $validatedPhone");
                    return [
                        'success' => false,
                        'message' => 'Failed to send OTP. Please try again.',
                        'otp_code' => $otpCode, // Return OTP for debugging
                        'expires_at' => $otp->expires_at->toDateTimeString()
                    ];
                }

                Log::info("OTP sent to $validatedPhone");
                return [
                    'success' => true,
                    'message' => 'OTP sent successfully via WhatsApp',
                    'expires_at' => $otp->expires_at->toDateTimeString()
                ];
            }

            // No API key - return OTP directly
            Log::info("OTP generated for $validatedPhone: $otpCode (no API key)");
            return [
                'success' => true,
                'message' => 'OTP generated (no API key configured)',
                'otp_code' => $otpCode,
                'expires_at' => $otp->expires_at->toDateTimeString()
            ];

        } catch (\Exception $e) {
            Log::error('OTP send error: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to send OTP. Please try again.'
            ];
        }
    }

    /**
     * Verify OTP
     */
    public function verifyOtp(string $phoneNumber, string $code): array
    {
        try {
            $validatedPhone = $this->validateSyrianPhone($phoneNumber);

            $otp = $this->otpRepository->findByPhoneAndCode($validatedPhone, $code);

            if (!$otp) {
                return [
                    'success' => false,
                    'message' => 'Invalid or expired OTP'
                ];
            }

            if (!$otp->isValid()) {
                return [
                    'success' => false,
                    'message' => 'OTP has expired or exceeded maximum attempts'
                ];
            }

            // Mark as verified
            $otp->markAsVerified();

            return [
                'success' => true,
                'message' => 'OTP verified successfully',
                'data' => [
                    'phone_number' => $validatedPhone,
                    'verified_at' => $otp->verified_at->toDateTimeString()
                ]
            ];

        } catch (\Exception $e) {
            Log::error('OTP verification error: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'OTP verification failed'
            ];
        }
    }

    /**
     * Send OTP via CallMeBot API (works with personal WhatsApp)
     */
    private function sendViaCallMeBot(string $phoneNumber, string $otpCode): bool
    {
        try {
            $normalizedPhone = $this->normalizeForCallMeBot($phoneNumber);
            $message = "Your verification code is: $otpCode\n\nThis code will expire in 5 minutes.\n\nDo not share this code with anyone.";

            $url = "https://api.callmebot.com/whatsapp.php?" . http_build_query([
                    'phone' => $normalizedPhone,
                    'text' => $message,
                    'apikey' => $this->apiKey
                ]);

            // Create client with disabled SSL verification
            $insecureClient = new Client(['verify' => false]);

            $response = $insecureClient->get($url);
            $statusCode = $response->getStatusCode();
            $responseBody = $response->getBody()->getContents();

            // Consider 200 or 203 as success regardless of response body
            if ($statusCode === 200 || $statusCode === 203) {
                Log::info("OTP sent successfully to $phoneNumber. Response status: $statusCode");
                return true;
            }

            Log::error("CallMeBot failed. Status: $statusCode | Response: $responseBody");
            return false;

        } catch (RequestException $e) {
            Log::error('CallMeBot API error: ' . $e->getMessage());
            return false;
        }
    }
    /**
     * Normalize phone for CallMeBot (963XXXXXXXXX format)
     */
    private function normalizeForCallMeBot(string $phoneNumber): string
    {
        $clean = preg_replace('/[^0-9]/', '', $phoneNumber);
        $clean = ltrim($clean, '0');

        if (str_starts_with($clean, '9639') && strlen($clean) === 12) {
            return $clean; // Already in 9639XXXXXXXX format
        }

        if (str_starts_with($clean, '9') && strlen($clean) === 9) {
            return '963' . $clean; // 9XXXXXXXX → 9639XXXXXXXX
        }

        return $clean;
    }

    /**
     * Validate Syrian phone number
     */
    private function validateSyrianPhone(string $phoneNumber): string
    {
        $phone = preg_replace('/[^0-9]/', '', $phoneNumber);
        $phone = ltrim($phone, '0');

        // Handle all valid formats
        if (strlen($phone) === 9 && $phone[0] === '9') {
            return '+963' . $phone; // +963983337214
        } elseif (strlen($phone) === 12 && substr($phone, 0, 3) === '963' && $phone[3] === '9') {
            return '+' . $phone;    // +963983337214
        } elseif (strlen($phone) === 10 && substr($phone, 0, 2) === '09') {
            return '+963' . substr($phone, 1); // 0983337214 → +963983337214
        }

        throw new \InvalidArgumentException('Invalid Syrian phone number format');
    }

    /**
     * Check if OTP can be sent (rate limiting)
     */
    private function canSendOtp(string $phoneNumber): bool
    {
        $recentAttempts = $this->otpRepository->getRecentAttempts($phoneNumber, 5);
        return $recentAttempts < 3; // Max 3 attempts per 5 minutes
    }
}
