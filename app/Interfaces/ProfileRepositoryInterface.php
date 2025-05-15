<?php

namespace App\Interfaces;

use App\Models\User;

interface ProfileRepositoryInterface
{
    public function createProfile(array $data);
    public function updateProfile($userId, array $data);
    public function getProfileByUserId($userId);
    public function deleteProfile($userId);
    public function updateOrCreateProfile($userId, array $data);
    public function getProfileWithUser($userId);
    public function createFromUser(User $user);
}
