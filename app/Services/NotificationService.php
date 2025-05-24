<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserNotification;
use App\Models\Notification;
use App\Events\NotificationSent;
use App\Services\PushNotificationService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class NotificationService
{
    public function __construct(
        private PushNotificationService $pushService
    ) {}

    public function getUserNotifications(User $user, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = UserNotification::with('notification')
            ->where('user_id', $user->id)
            ->orderBy('created_at', 'desc');

        // Apply filters
        if (!empty($filters['category'])) {
            $query->whereHas('notification', function($q) use ($filters) {
                $q->where('type', $filters['category']);
            });
        }

        if (isset($filters['is_read'])) {
            if ($filters['is_read']) {
                $query->whereNotNull('read_at');
            } else {
                $query->whereNull('read_at');
            }
        }

        return $query->paginate($perPage);
    }

    public function createNotification(
        User $user,
        string $type,
        string $title,
        string $message,
        array $data = [],
        string $priority = 'normal',
        string $category = 'general'
    ): UserNotification {
        // Create base notification
        $notification = Notification::create([
            'title' => $title,
            'message' => $message,
            'type' => $type,
            'data' => $data,
            'sent_at' => now()
        ]);

        // Create user notification
        $userNotification = UserNotification::create([
            'user_id' => $user->id,
            'notification_id' => $notification->id
        ]);

        // Send push notification
        $this->pushService->sendToUser($user, [
            'title' => $title,
            'body' => $message,
            'data' => array_merge($data, [
                'notification_id' => $notification->id,
                'type' => $type,
                'category' => $category
            ])
        ]);

        // Broadcast real-time notification
        broadcast(new NotificationSent($user, $notification));

        return $userNotification->load('notification');
    }

    public function markAsRead(UserNotification $notification): void
    {
        $notification->markAsRead();
    }

    public function markAllAsRead(User $user): void
    {
        UserNotification::where('user_id', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    public function deleteNotification(UserNotification $notification): void
    {
        $notification->delete();
    }
}
