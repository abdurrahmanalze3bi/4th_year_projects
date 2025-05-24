<?php
namespace App\Console\Commands;

use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Console\Command;

class TestNotificationCommand extends Command
{
    protected $signature = 'notification:test {user_id?} {--type=welcome}';
    protected $description = 'Send a test notification to a user';

    public function handle(NotificationService $notificationService)
    {
        $userId = $this->argument('user_id') ?? User::first()?->id;
        $type = $this->option('type');

        if (!$userId) {
            $this->error('No user found to send notification to');
            return 1;
        }

        try {
            switch ($type) {
                case 'welcome':
                    $notification = $notificationService->createWelcomeNotification($userId);
                    break;
                case 'system':
                    $notification = $notificationService->createSystemNotification(
                        'Test System Notification',
                        'This is a test system notification sent from the command line.',
                        [$userId]
                    );
                    break;
                default:
                    $notification = $notificationService->create([
                        'title' => 'Test Notification',
                        'message' => 'This is a test notification!',
                        'type' => 'test',
                        'user_id' => $userId
                    ]);
            }

            $this->info("Test notification sent successfully! ID: {$notification->id}");
            return 0;

        } catch (\Exception $e) {
            $this->error('Failed to send test notification: ' . $e->getMessage());
            return 1;
        }
    }
}
