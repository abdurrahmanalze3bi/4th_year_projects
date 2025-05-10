<?php

namespace App\Interfaces;

// app/Interfaces/UserRepositoryInterface.php
// app/Interfaces/UserRepositoryInterface.php
interface UserRepositoryInterface {
    public function createUser(array $data);
    public function updateUserStatus($userId, $status);
    public function findByEmail($email); // Add new method
}
