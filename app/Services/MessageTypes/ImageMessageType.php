<?php

namespace App\Services\MessageTypes;

use App\Interfaces\MessageTypeInterface;
use Illuminate\Support\Facades\Validator;

class ImageMessageType implements MessageTypeInterface
{
    /**
     * Validate image message data
     */
    public function validate(array $data): bool
    {
        $validator = Validator::make($data, [
            'image' => 'required|image|max:10240', // 10MB max
            'caption' => 'nullable|string|max:500',
        ]);

        return !$validator->fails();
    }

    /**
     * Process image message data
     */
    public function process(array $data): array
    {
        // Here you would typically handle image upload and storage
        // For now, we'll just return a placeholder

        $imagePath = $data['image']->store('chat-images', 'public');
        $imageUrl = asset('storage/' . $imagePath);

        return [
            'content' => $data['caption'] ?? '',
            'metadata' => [
                'image_url' => $imageUrl,
                'image_name' => $data['image']->getClientOriginalName(),
                'image_size' => $data['image']->getSize(),
                'image_mime' => $data['image']->getMimeType(),
            ]
        ];
    }
}
