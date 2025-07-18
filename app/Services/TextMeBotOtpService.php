<?php

namespace App\Services;
use App\Interfaces\OtpRepositoryInterface;
use App\Models\Otp;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;

class TextMeBotOtpService
{
    protected $otpRepository;
    protected $apiKey;

    public function __construct(OtpRepositoryInterface $otpRepository)
    {
        $this->otpRepository = $otpRepository;
        $this->apiKey = env('TEXTMEBOT_API_KEY');
    }

    /**
     * Send OTP via WhatsApp using TextMeBot
     */
    public function sendOtp(string $phoneNumber, string $type = 'E-PAYMENT'): array

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
                $sent = $this->sendViaTextMeBot($validatedPhone, $otpCode);

                if (!$sent) {
                    Log::error("TextMeBot: Failed to send OTP to $validatedPhone");
                    return [
                        'success' => false,
                        'message' => 'Failed to send OTP. Please try again.',
                        'otp_code' => $otpCode,
                        'expires_at' => $otp->expires_at->toDateTimeString()
                    ];
                }

                Log::info("TextMeBot: OTP sent to $validatedPhone");
                return [
                    'success' => true,
                    'message' => 'OTP sent successfully via WhatsApp (TextMeBot)',
                    'expires_at' => $otp->expires_at->toDateTimeString()
                ];
            }

            Log::info("TextMeBot: OTP generated for $validatedPhone: $otpCode (no API key)");
            return [
                'success' => true,
                'message' => 'OTP generated (TextMeBot not configured)',
                'otp_code' => $otpCode,
                'expires_at' => $otp->expires_at->toDateTimeString()
            ];

        } catch (\Exception $e) {
            Log::error('TextMeBot OTP send error: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Failed to send OTP. Please try again.'
            ];
        }
    }

    /**
     * Verify OTP (same as WhatsAppOtpService)
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
     * Send OTP via TextMeBot API
     */
    private function sendViaTextMeBot(string $phoneNumber, string $otpCode): bool
    {
        // Add 5-second delay to prevent WhatsApp blocking
        sleep(5);

        try {
            $normalizedPhone = $this->normalizeForTextMeBot($phoneNumber);
            $message = "Your verification code is: $otpCode\n\nThis code will expire in 5 minutes.\n\nDo not share this code with anyone.";

            $url = "http://api.textmebot.com/send.php?" . http_build_query([
                    'recipient' => $normalizedPhone,
                    'apikey' => $this->apiKey,
                    'text' => $message,
                    'json' => 'yes' // Request JSON response
                ]);

            $client = new Client();
            $response = $client->get($url);
            $responseData = json_decode($response->getBody()->getContents(), true);

            if (isset($responseData['status']) && $responseData['status'] === 'success') {
                Log::info("TextMeBot: OTP sent successfully to $phoneNumber");
                return true;
            }

            Log::error("TextMeBot failed. Response: " . json_encode($responseData));
            return false;

        } catch (RequestException $e) {
            Log::error('TextMeBot API error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Normalize phone for TextMeBot (+963 format)
     */
    private function normalizeForTextMeBot(string $phoneNumber): string
    {
        $clean = preg_replace('/[^0-9]/', '', $phoneNumber);
        $clean = ltrim($clean, '0');

        if (str_starts_with($clean, '9639') && strlen($clean) === 12) {
            return '+' . $clean; // +9639XXXXXXXX
        }

        if (str_starts_with($clean, '9') && strlen($clean) === 9) {
            return '+963' . $clean; // +9639XXXXXXXX
        }

        return '+' . $clean;
    }

    /**
     * Validate Syrian phone number
     */
    private function validateSyrianPhone(string $phoneNumber): string
    {
        $phone = preg_replace('/[^0-9]/', '', $phoneNumber);
        $phone = ltrim($phone, '0');

        if (strlen($phone) === 9 && $phone[0] === '9') {
            return '+963' . $phone;
        } elseif (strlen($phone) === 12 && substr($phone, 0, 3) === '963' && $phone[3] === '9') {
            return '+' . $phone;
        } elseif (strlen($phone) === 10 && substr($phone, 0, 2) === '09') {
            return '+963' . substr($phone, 1);
        }

        throw new \InvalidArgumentException('Invalid Syrian phone number format');
    }

    /**
     * Check if OTP can be sent (rate limiting)
     */
    private function canSendOtp(string $phoneNumber): bool
    {
        $recentAttempts = $this->otpRepository->getRecentAttempts($phoneNumber, 5);
        return $recentAttempts < 3;
    }
}
