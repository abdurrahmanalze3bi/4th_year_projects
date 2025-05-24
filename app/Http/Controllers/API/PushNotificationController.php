<?php
namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\NotificationService;
use App\Services\PushNotificationService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class PushNotificationController extends Controller
{
    public function __construct(
        private PushNotificationService $pushService
    ) {}

    public function registerToken(Request $request): JsonResponse
    {
        $request->validate([
            'token' => 'required|string',
            'platform' => 'required|in:android,ios,web',
            'device_id' => 'nullable|string',
            'device_name' => 'nullable|string',
        ]);

        $token = $this->pushService->registerToken(
            $request->user(),
            $request->token,
            $request->platform,
            $request->only(['device_id', 'device_name'])
        );

        return response()->json([
            'success' => true,
            'message' => 'Push notification token registered successfully',
            'data' => $token
        ]);
    }

    public function removeToken(Request $request): JsonResponse
    {
        $request->validate([
            'token' => 'required|string'
        ]);

        $removed = $this->pushService->removeToken($request->token);

        return response()->json([
            'success' => $removed,
            'message' => $removed ? 'Token removed successfully' : 'Token not found'
        ]);
    }

    public function getUserTokens(Request $request): JsonResponse
    {
        $tokens = $request->user()->pushTokens()->active()->get();

        return response()->json([
            'success' => true,
            'data' => $tokens
        ]);
    }

    public function testNotification(Request $request): JsonResponse
    {
        // Only allow in development
        if (!app()->environment('local')) {
            return response()->json([
                'success' => false,
                'message' => 'Test notifications are only available in development'
            ], 403);
        }

        $notification = app(NotificationService::class)->createNotification(
            $request->user(),
            'test',
            'Test Notification',
            'This is a test notification',
            ['test' => true],
            'normal',
            'system'
        );

        return response()->json([
            'success' => true,
            'message' => 'Test notification sent',
            'data' => $notification
        ]);
    }
}
