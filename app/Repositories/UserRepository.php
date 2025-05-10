<?php

namespace App\Repositories;

use App\Interfaces\UserRepositoryInterface;
use App\Models\User;

class UserRepository implements UserRepositoryInterface {

    protected $model;

    public function __construct(User $user)
    {
        $this->model = $user;
    }

    public function createUser(array $data)
    {
        return $this->model->create([
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'email' => $data['email'],
            'password' => bcrypt($data['password']),
            'gender' => $data['gender'],
            'address' => $data['address'],
            'status' => 1
        ]);
    }
    // app/Repositories/UserRepository.php
    public function updateUserStatus($userId, $status) {
        $user = $this->model->findOrFail($userId);
        $user->status = $status;
        $user->save();
        return $user;
    }
    // app/Repositories/UserRepository.php
    public function findByEmail($email) {
        return $this->model->where('email', $email)->first();
    }
}
