<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Interfaces\PasswordResetRepositoryInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;

class ForgotPasswordController extends Controller
{
    private $passwordResetRepo;

    public function __construct(PasswordResetRepositoryInterface $passwordResetRepo)
    {
        $this->passwordResetRepo = $passwordResetRepo;
    }

    public function __invoke(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $status = $this->passwordResetRepo->sendResetLink(
            $request->only('email')
        );

        return response()->json([
            'message' => __($status)
        ], $status === Password::RESET_LINK_SENT ? 200 : 400);
    }
}
