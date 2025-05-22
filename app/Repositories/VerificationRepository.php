<?php

namespace App\Repositories;

use App\Interfaces\VerificationRepositoryInterface;
use App\Models\User;
use App\Interfaces\ProfileRepositoryInterface;

class VerificationRepository implements VerificationRepositoryInterface
{
    private ProfileRepositoryInterface $profileRepo;

    public function __construct(ProfileRepositoryInterface $profileRepo)
    {
        $this->profileRepo = $profileRepo;
    }

    /**
     * Admin approves a passenger verification request.
     *
     * @param  mixed  $userId
     * @return User
     * @throws \Exception if not in pending state
     */
    public function verifyPassenger($userId)
    {
        $user = User::findOrFail($userId);

        // Only allow approving if status is exactly 'pending'
        if ($user->verification_status !== 'pending') {
            throw new \Exception('Cannot approve passenger: not in pending state');
        }

        $user->update([
            'is_verified_passenger' => true,
            'verification_status'   => 'approved',
        ]);

        return $user;
    }

    /**
     * Admin approves a driver verification request.
     *
     * @param  mixed  $userId
     * @return User
     * @throws \Exception if not in pending state
     */
    public function verifyDriver($userId)
    {
        $user = User::findOrFail($userId);

        // Only allow approving if status is exactly 'pending'
        if ($user->verification_status !== 'pending') {
            throw new \Exception('Cannot approve driver: not in pending state');
        }

        $user->update([
            'is_verified_passenger' => true,
            'is_verified_driver'    => true,
            'verification_status'   => 'approved',
        ]);

        return $user;
    }
}
