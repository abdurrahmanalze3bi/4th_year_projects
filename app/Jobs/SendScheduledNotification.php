<?php
namespace App\Jobs;

use App\Services\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendScheduledNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $notificationData;

    public function __construct(array $notificationData)
    {
        $this->notificationData = $notificationData;
    }

    public function handle(NotificationService $notificationService): void
    {
        try {
            $notificationService->create($this->notificationData);
            Log::info('Scheduled notification sent successfully', $this->notificationData);
        } catch (\Exception $e) {
            Log::error('Failed to send scheduled notification: ' . $e->getMessage(), $this->notificationData);
            throw $e;
        }
    }
}
