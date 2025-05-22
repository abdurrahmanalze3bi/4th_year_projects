<?php
namespace App\Repositories;

use App\Interfaces\PhotoRepositoryInterface;
use App\Models\Photo;

class PhotoRepository implements PhotoRepositoryInterface {
    public function storeDocument($userId, $type, $path) {
        return Photo::create([
            'user_id' => $userId,
            'type' => $type,
            'path' => $path
        ]);
    }

    public function deleteDocumentsByType($userId, $type) {
        return Photo::where('user_id', $userId)
            ->where('type', $type)
            ->delete();
    }

    public function getUserDocumentsByType($userId, $types) {
        return Photo::where('user_id', $userId)
            ->whereIn('type', $types)
            ->get();
    }
}
