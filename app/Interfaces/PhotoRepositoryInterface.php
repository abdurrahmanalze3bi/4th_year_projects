<?php
namespace App\Interfaces;

interface PhotoRepositoryInterface {
    public function storeDocument($userId, $type, $path);
    public function deleteDocumentsByType($userId, $type);
    public function getUserDocumentsByType($userId, $types);
}
