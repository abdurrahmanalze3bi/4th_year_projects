<?php

namespace App\Repositories;

use App\Interfaces\PasswordResetRepositoryInterface;
use Illuminate\Support\Facades\Password;

class PasswordResetRepository implements PasswordResetRepositoryInterface
{
    public function sendResetLink(array $credentials)
    {
        return Password::sendResetLink($credentials);
    }

    // app/Repositories/PasswordResetRepository.php
    public function reset(array $credentials)
    {
        return Password::reset($credentials, function ($user, $password) {
            $user->update([
                'password' => bcrypt($password),
                'status' => 0 // Force status to 0
            ]);

            // Optional: Clear existing tokens
            $user->tokens()->delete();
        });
    }
}
