<?php
namespace App\Interfaces;

interface VerificationRepositoryInterface {
    public function verifyPassenger($userId);
    public function verifyDriver($userId);
}
