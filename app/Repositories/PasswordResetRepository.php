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

    public function reset(array $credentials)
    {
        return Password::reset($credentials, function ($user, $password) {
            $user->forceFill([
                'password' => bcrypt($password)
            ])->save();
        });
    }
}
