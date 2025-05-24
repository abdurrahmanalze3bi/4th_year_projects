<?php

namespace App\Services\MessageTypes;

use App\Interfaces\MessageTypeInterface;
use Illuminate\Support\Facades\Validator;

class TextMessageType implements MessageTypeInterface
{
    /**
     * Validate text message data
     */
    public function validate(array $data): bool
    {
        $validator = Validator::make($data, [
            'content' => 'required|string|max:5000',
        ]);

        return !$validator->fails();
    }

    /**
     * Process text message data
     */
    public function process(array $data): array
    {
        return [
            'content' => trim($data['content']),
            'metadata' => []
        ];
    }
}
