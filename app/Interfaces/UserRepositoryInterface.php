<?php

namespace App\Interfaces;

// app/Interfaces/UserRepositoryInterface.php
// app/Interfaces/UserRepositoryInterface.php
interface UserRepositoryInterface {
    public function createUser(array $data);
    public function updateUserStatus($userId, $status);
    public function findByEmail($email);
    public function findByGoogleId($googleId); // Add this
    public function updateGoogleId($userId, $googleId); // Add this
}
