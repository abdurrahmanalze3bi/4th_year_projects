<?php

namespace App\Repositories;

use App\Interfaces\ProfileRepositoryInterface;
use App\Models\Profile;
use App\Models\User;

class ProfileRepository implements ProfileRepositoryInterface
{
    protected $model;

    public function __construct(Profile $profile)
    {
        $this->model = $profile;
    }

    public function createProfile(array $data)
    {
        return $this->model->create($data);
    }

    public function updateProfile($userId, array $data)
    {
        $profile = $this->model->where('user_id', $userId)->firstOrFail();
        $profile->update($data);
        return $profile;
    }

    public function getProfileByUserId($userId)
    {
        return $this->model->where('user_id', $userId)->first();
    }

    public function deleteProfile($userId)
    {
        return $this->model->where('user_id', $userId)->delete();
    }

    public function updateOrCreateProfile($userId, array $data)
    {
        $profile = $this->model->updateOrCreate(
            ['user_id' => $userId],
            $data
        );
        return $profile->fresh(); // Add this to get refreshed data
    }

    // app/Repositories/ProfileRepository.php

    public function getProfileWithUser($userId)
    {
        return $this->model
            ->with(['user', 'comments.commenter'])
            ->where('user_id', $userId)
            ->firstOrFail();
    }




    public function createFromUser(User $user)
    {
        return $this->model->create([
            'user_id' => $user->id,
            'full_name' => $user->first_name.' '.$user->last_name,
            'address' => $user->address,
            'gender' => $user->gender
        ]);
    }
}
