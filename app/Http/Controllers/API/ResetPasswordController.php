<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Interfaces\PasswordResetRepositoryInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;

class ResetPasswordController extends Controller
{
    private $passwordResetRepo;

    public function __construct(PasswordResetRepositoryInterface $passwordResetRepo)
    {
        $this->passwordResetRepo = $passwordResetRepo;
    }

    public function __invoke(Request $request)
    {
        $request->validate([
            'token' => 'required',
            'email' => 'required|email',
            'password' => 'required|confirmed|min:8',
        ]);

        $status = $this->passwordResetRepo->reset(
            $request->only('email', 'password', 'password_confirmation', 'token')
        );

        return response()->json([
            'message' => __($status)
        ], $status === Password::PASSWORD_RESET ? 200 : 400);
    }
}
