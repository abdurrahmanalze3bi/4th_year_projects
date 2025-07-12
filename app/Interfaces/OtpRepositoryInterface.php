<?php

namespace App\Interfaces;

use App\Models\Otp;

interface OtpRepositoryInterface
{
    /**
     * Create a new OTP
     */
    public function create(array $data): Otp;

    /**
     * Find OTP by phone number and code
     */
    public function findByPhoneAndCode(string $phoneNumber, string $code): ?Otp;

    /**
     * Find active OTP by phone number and type
     */
    public function findActiveByPhoneAndType(string $phoneNumber, string $type): ?Otp;

    /**
     * Delete expired OTPs
     */
    public function deleteExpired(): int;

    /**
     * Delete OTPs by phone number
     */
    public function deleteByPhone(string $phoneNumber): int;

    /**
     * Get recent OTP attempts for phone number
     */
    public function getRecentAttempts(string $phoneNumber, int $minutes = 5): int;
}
