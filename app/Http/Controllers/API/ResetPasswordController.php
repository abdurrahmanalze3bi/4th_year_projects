<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Interfaces\PasswordResetRepositoryInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class ResetPasswordController extends Controller
{
    private $passwordResetRepo;

    public function __construct(PasswordResetRepositoryInterface $passwordResetRepo)
    {
        $this->passwordResetRepo = $passwordResetRepo;
    }

    public function __invoke(Request $request)
    {
        // Enhanced validation with custom messages
        $validator = Validator::make($request->all(), [
            'token' => 'required|string',
            'email' => 'required|email|exists:users,email',
            'password' => 'required|confirmed|min:8', // Removed regex validation
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $status = $this->passwordResetRepo->reset(
                $request->only('email', 'password', 'password_confirmation', 'token')
            );

            Log::info("Password reset attempt for email: {$request->email}", [
                'status' => $status,
                'ip' => $request->ip()
            ]);

            return response()->json([
                'success' => $status === Password::PASSWORD_RESET,
                'message' => __($status)
            ], $status === Password::PASSWORD_RESET ? 200 : 400);

        } catch (\Exception $e) {
            Log::error("Password reset error: {$e->getMessage()}", [
                'email' => $request->email,
                'token' => substr($request->token, 0, 10) . '...' // Partial token for security
            ]);

            return response()->json([
                'success' => $status === Password::PASSWORD_RESET,
                'message' => __($status),
                'redirect' => $status === Password::PASSWORD_RESET
                    ? url('/login')
                    : null
            ], $status === Password::PASSWORD_RESET ? 200 : 400);
        }
    }
}
