<?php

namespace App\Interfaces;

interface PasswordResetRepositoryInterface {
    public function sendResetLink(array $credentials);
    public function reset(array $credentials);
}
