<?php

namespace App\Observers;

use App\Models\User; // <-- Fix the import
use App\Interfaces\ProfileRepositoryInterface;

class UserObserver
{
    public function __construct(
        private ProfileRepositoryInterface $profileRepo
    ) {}

    // Fix the type hint to use App\Models\User
    public function created(User $user) // <-- Correct parameter type
    {
        $this->profileRepo->createFromUser($user);
    }
}
