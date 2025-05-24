<?php

namespace App\Services;

use App\Models\User;
use App\Models\PushNotificationToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class PushNotificationService
{
    protected $fcmServerKey;
    protected $fcmUrl = 'https://fcm.googleapis.com/fcm/send';

    public function __construct()
    {
        $this->fcmServerKey = config('services.fcm.server_key');
    }

    /**
     * Register a push notification token for a user
     */
    public function registerToken($userId, $token, $deviceType = 'android')
    {
        try {
            // Check if token already exists
            $existingToken = PushNotificationToken::where('token', $token)->first();

            if ($existingToken) {
                // Update existing token with new user (in case device changed hands)
                $existingToken->update([
                    'user_id' => $userId,
                    'device_type' => $deviceType,
                    'is_active' => true,
                    'updated_at' => now()
                ]);
                return $existingToken;
            }

            // Create new token
            return PushNotificationToken::create([
                'user_id' => $userId,
                'token' => $token,
                'device_type' => $deviceType,
                'is_active' => true,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to register push token: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Remove a push notification token
     */
    public function removeToken($token)
    {
        return PushNotificationToken::where('token', $token)
            ->update(['is_active' => false]);
    }

    /**
     * Remove all tokens for a user (when they logout)
     */
    public function removeUserTokens($userId)
    {
        return PushNotificationToken::where('user_id', $userId)
            ->update(['is_active' => false]);
    }

    /**
     * Send push notification to a specific user
     */
    public function sendToUser(User $user, array $data)
    {
        $tokens = $this->getUserTokens($user->id);

        if ($tokens->isEmpty()) {
            Log::info("No active tokens found for user: {$user->id}");
            return false;
        }

        return $this->sendToTokens($tokens->pluck('token')->toArray(), $data);
    }

    /**
     * Send push notification to multiple users
     */
    public function sendToUsers(array $userIds, array $data)
    {
        $tokens = PushNotificationToken::whereIn('user_id', $userIds)
            ->where('is_active', true)
            ->pluck('token')
            ->toArray();

        if (empty($tokens)) {
            Log::info("No active tokens found for users: " . implode(',', $userIds));
            return false;
        }

        return $this->sendToTokens($tokens, $data);
    }

    /**
     * Send push notification to specific tokens
     */
    public function sendToTokens(array $tokens, array $data)
    {
        if (empty($tokens) || !$this->fcmServerKey) {
            Log::warning('No tokens provided or FCM server key not configured');
            return false;
        }

        $chunks = array_chunk($tokens, 1000); // FCM limit is 1000 tokens per request
        $results = [];

        foreach ($chunks as $tokenChunk) {
            $result = $this->sendFCMNotification($tokenChunk, $data);
            $results[] = $result;

            // Handle invalid tokens
            if ($result && isset($result['results'])) {
                $this->handleInvalidTokens($tokenChunk, $result['results']);
            }
        }

        return $results;
    }

    /**
     * Send notification to a topic (broadcast)
     */
    public function sendToTopic($topic, array $data)
    {
        if (!$this->fcmServerKey) {
            Log::warning('FCM server key not configured');
            return false;
        }

        $payload = [
            'to' => '/topics/' . $topic,
            'notification' => [
                'title' => $data['title'],
                'body' => $data['body'],
                'icon' => $data['icon'] ?? 'default',
                'sound' => $data['sound'] ?? 'default',
            ],
            'data' => $data['data'] ?? []
        ];

        return $this->sendFCMRequest($payload);
    }

    /**
     * Subscribe user to a topic
     */
    public function subscribeToTopic($userId, $topic)
    {
        $tokens = $this->getUserTokens($userId);

        if ($tokens->isEmpty()) {
            return false;
        }

        $response = Http::withHeaders([
            'Authorization' => 'key=' . $this->fcmServerKey,
            'Content-Type' => 'application/json',
        ])->post('https://iid.googleapis.com/iid/v1:batchAdd', [
            'to' => '/topics/' . $topic,
            'registration_tokens' => $tokens->pluck('token')->toArray()
        ]);

        return $response->successful();
    }

    /**
     * Unsubscribe user from a topic
     */
    public function unsubscribeFromTopic($userId, $topic)
    {
        $tokens = $this->getUserTokens($userId);

        if ($tokens->isEmpty()) {
            return false;
        }

        $response = Http::withHeaders([
            'Authorization' => 'key=' . $this->fcmServerKey,
            'Content-Type' => 'application/json',
        ])->post('https://iid.googleapis.com/iid/v1:batchRemove', [
            'to' => '/topics/' . $topic,
            'registration_tokens' => $tokens->pluck('token')->toArray()
        ]);

        return $response->successful();
    }

    /**
     * Get active tokens for a user
     */
    protected function getUserTokens($userId)
    {
        return PushNotificationToken::where('user_id', $userId)
            ->where('is_active', true)
            ->get();
    }

    /**
     * Send FCM notification to multiple tokens
     */
    protected function sendFCMNotification(array $tokens, array $data)
    {
        $payload = [
            'registration_ids' => $tokens,
            'notification' => [
                'title' => $data['title'],
                'body' => $data['body'],
                'icon' => $data['icon'] ?? 'default',
                'sound' => $data['sound'] ?? 'default',
                'click_action' => $data['click_action'] ?? null,
            ],
            'data' => $data['data'] ?? [],
            'priority' => 'high',
            'time_to_live' => 3600, // 1 hour
        ];

        return $this->sendFCMRequest($payload);
    }

    /**
     * Send FCM request
     */
    protected function sendFCMRequest(array $payload)
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'key=' . $this->fcmServerKey,
                'Content-Type' => 'application/json',
            ])->post($this->fcmUrl, $payload);

            if ($response->successful()) {
                $result = $response->json();
                Log::info('FCM notification sent successfully', [
                    'success' => $result['success'] ?? 0,
                    'failure' => $result['failure'] ?? 0
                ]);
                return $result;
            } else {
                Log::error('FCM request failed', [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
                return false;
            }

        } catch (\Exception $e) {
            Log::error('FCM request exception: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Handle invalid tokens and remove them
     */
    protected function handleInvalidTokens(array $tokens, array $results)
    {
        $invalidTokens = [];

        foreach ($results as $index => $result) {
            if (isset($result['error'])) {
                $error = $result['error'];
                if (in_array($error, ['NotRegistered', 'InvalidRegistration'])) {
                    $invalidTokens[] = $tokens[$index];
                }
            }
        }

        if (!empty($invalidTokens)) {
            PushNotificationToken::whereIn('token', $invalidTokens)
                ->update(['is_active' => false]);

            Log::info('Removed invalid FCM tokens', ['count' => count($invalidTokens)]);
        }
    }

    /**
     * Test notification (for debugging)
     */
    public function sendTestNotification($userId)
    {
        return $this->sendToUser(
            User::find($userId),
            [
                'title' => 'Test Notification',
                'body' => 'This is a test notification from your app!',
                'data' => ['test' => true]
            ]
        );
    }

    /**
     * Get notification statistics
     */
    public function getStats()
    {
        return [
            'total_tokens' => PushNotificationToken::count(),
            'active_tokens' => PushNotificationToken::where('is_active', true)->count(),
            'android_tokens' => PushNotificationToken::where('device_type', 'android')->where('is_active', true)->count(),
            'ios_tokens' => PushNotificationToken::where('device_type', 'ios')->where('is_active', true)->count(),
            'web_tokens' => PushNotificationToken::where('device_type', 'web')->where('is_active', true)->count(),
        ];
    }

    /**
     * Clean up old inactive tokens
     */
    public function cleanupInactiveTokens($days = 30)
    {
        $count = PushNotificationToken::where('is_active', false)
            ->where('updated_at', '<', now()->subDays($days))
            ->delete();

        Log::info("Cleaned up {$count} inactive push tokens older than {$days} days");
        return $count;
    }
}
