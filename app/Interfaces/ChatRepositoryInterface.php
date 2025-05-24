<?php

namespace App\Interfaces;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

interface ChatRepositoryInterface
{
    public function createConversation(array $participants, string $type = 'private', ?string $title = null): Conversation;

    public function findConversation(int $conversationId): ?Conversation;

    public function findPrivateConversation(User $user1, User $user2): ?Conversation;

    public function getUserConversations(User $user): Collection;

    public function sendMessage(int $conversationId, int $senderId, string $content, string $type = 'text', ?array $metadata = null): Message;

    public function getMessages(int $conversationId, int $limit = 50, int $offset = 0): Collection;

    public function markMessageAsRead(int $messageId, int $userId): bool;

    public function deleteMessage(int $messageId, int $userId): bool;
}
