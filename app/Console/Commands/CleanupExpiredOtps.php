<?php

namespace App\Console\Commands;

use App\Interfaces\OtpRepositoryInterface;
use Illuminate\Console\Command;

class CleanupExpiredOtps extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'otp:cleanup';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up expired OTP records';

    protected $otpRepository;

    /**
     * Create a new command instance.
     */
    public function __construct(OtpRepositoryInterface $otpRepository)
    {
        parent::__construct();
        $this->otpRepository = $otpRepository;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $deletedCount = $this->otpRepository->deleteExpired();

        $this->info("Cleaned up {$deletedCount} expired OTP records.");

        return 0;
    }
}
