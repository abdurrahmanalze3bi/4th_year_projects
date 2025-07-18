<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\SendOtpRequest;
use App\Http\Requests\VerifyOtpRequest;
use App\Services\TextMeBotOtpService;
use Illuminate\Http\JsonResponse;

class TextMeOtpController extends Controller
{
    protected $otpService;

    public function __construct(TextMeBotOtpService $otpService)
    {
        $this->otpService = $otpService;
    }

    /**
     * Send OTP via TextMeBot
     * POST /api/textme-otp/send
     */
    public function sendOtp(SendOtpRequest $request): JsonResponse
    {
        if (!env('TEXTMEBOT_ENABLED', false)) {
            return response()->json([
                'success' => false,
                'message' => 'TextMeBot service is currently disabled'
            ], 400);
        }

        $result = $this->otpService->sendOtp(
            $request->phone_number,
            $request->type ?? 'E-PAYMENT'
        );

        return response()->json($result, $result['success'] ? 200 : 400);
    }

    /**
     * Verify OTP
     * POST /api/textme-otp/verify
     */
    public function verifyOtp(VerifyOtpRequest $request): JsonResponse
    {
        $result = $this->otpService->verifyOtp(
            $request->phone_number,
            $request->otp_code
        );

        return response()->json($result, $result['success'] ? 200 : 400);
    }
}
