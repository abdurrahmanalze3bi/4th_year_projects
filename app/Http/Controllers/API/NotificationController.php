<?php


// app/Http/Controllers/API/NotificationController.php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\NotificationService;
use App\Models\UserNotification;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class NotificationController extends Controller
{
    public function __construct(
        private NotificationService $notificationService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['category', 'type', 'priority', 'is_read']);
        $perPage = $request->get('per_page', 15);

        $notifications = $this->notificationService->getUserNotifications(
            $request->user(),
            $filters,
            $perPage
        );

        return response()->json([
            'success' => true,
            'data' => $notifications,
            'unread_count' => $request->user()->unread_notifications_count,
        ]);
    }

    public function show(UserNotification $notification): JsonResponse
    {
        // Ensure user can only see their own notifications
        if ($notification->user_id !== auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'Notification not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $notification
        ]);
    }

    public function markAsRead(UserNotification $notification): JsonResponse
    {
        if ($notification->user_id !== auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'Notification not found'
            ], 404);
        }

        $this->notificationService->markAsRead($notification);

        return response()->json([
            'success' => true,
            'message' => 'Notification marked as read',
            'data' => $notification->fresh()
        ]);
    }

    public function markAsUnread(UserNotification $notification): JsonResponse
    {
        if ($notification->user_id !== auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'Notification not found'
            ], 404);
        }

        $notification->markAsUnread();

        return response()->json([
            'success' => true,
            'message' => 'Notification marked as unread',
            'data' => $notification->fresh()
        ]);
    }

    public function markAllAsRead(Request $request): JsonResponse
    {
        $this->notificationService->markAllAsRead($request->user());

        return response()->json([
            'success' => true,
            'message' => 'All notifications marked as read',
            'unread_count' => 0
        ]);
    }

    public function destroy(UserNotification $notification): JsonResponse
    {
        if ($notification->user_id !== auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'Notification not found'
            ], 404);
        }

        $this->notificationService->deleteNotification($notification);

        return response()->json([
            'success' => true,
            'message' => 'Notification deleted'
        ]);
    }

    public function getUnreadCount(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'unread_count' => $request->user()->unread_notifications_count
        ]);
    }

    public function getCategories(): JsonResponse
    {
        $categories = [
            'general' => 'General',
            'ride' => 'Rides',
            'chat' => 'Messages',
            'profile' => 'Profile',
            'system' => 'System'
        ];

        return response()->json([
            'success' => true,
            'data' => $categories
        ]);
    }

    public function bulkAction(Request $request): JsonResponse
    {
        $request->validate([
            'action' => 'required|in:mark_read,mark_unread,delete',
            'notification_ids' => 'required|array',
            'notification_ids.*' => 'exists:user_notifications,id'
        ]);

        $notifications = UserNotification::whereIn('id', $request->notification_ids)
            ->where('user_id', auth()->id())
            ->get();

        if ($notifications->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No notifications found'
            ], 404);
        }

        switch ($request->action) {
            case 'mark_read':
                $notifications->each(fn($n) => $this->notificationService->markAsRead($n));
                $message = 'Notifications marked as read';
                break;

            case 'mark_unread':
                $notifications->each(fn($n) => $n->markAsUnread());
                $message = 'Notifications marked as unread';
                break;

            case 'delete':
                $notifications->each(fn($n) => $this->notificationService->deleteNotification($n));
                $message = 'Notifications deleted';
                break;
        }

        return response()->json([
            'success' => true,
            'message' => $message,
            'unread_count' => $request->user()->unread_notifications_count
        ]);
    }
}
