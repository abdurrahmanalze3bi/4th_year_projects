<?php

namespace App\Repositories;

use App\Interfaces\OtpRepositoryInterface;
use App\Models\Otp;
use Carbon\Carbon;

class OtpRepository implements OtpRepositoryInterface
{
    protected $model;

    public function __construct(Otp $otp)
    {
        $this->model = $otp;
    }

    /**
     * Create a new OTP
     */
    public function create(array $data): Otp
    {
        return $this->model->create($data);
    }

    /**
     * Find OTP by phone number and code
     */
    public function findByPhoneAndCode(string $phoneNumber, string $code): ?Otp
    {
        return $this->model
            ->where('phone_number', $phoneNumber)
            ->where('otp_code', $code)
            ->active()
            ->first();
    }

    /**
     * Find active OTP by phone number and type
     */
    public function findActiveByPhoneAndType(string $phoneNumber, string $type): ?Otp
    {
        return $this->model
            ->where('phone_number', $phoneNumber)
            ->where('type', $type)
            ->active()
            ->first();
    }

    /**
     * Delete expired OTPs
     */
    public function deleteExpired(): int
    {
        return $this->model->expired()->delete();
    }

    /**
     * Delete OTPs by phone number
     */
    public function deleteByPhone(string $phoneNumber): int
    {
        return $this->model
            ->where('phone_number', $phoneNumber)
            ->delete();
    }

    /**
     * Get recent OTP attempts for phone number
     */
    public function getRecentAttempts(string $phoneNumber, int $minutes = 5): int
    {
        return $this->model
            ->where('phone_number', $phoneNumber)
            ->where('created_at', '>=', Carbon::now()->subMinutes($minutes))
            ->count();
    }
}
