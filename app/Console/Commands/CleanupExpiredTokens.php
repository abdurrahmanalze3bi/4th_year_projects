<?php

namespace App\Console\Commands;

use App\Services\JwtService;
use Illuminate\Console\Command;

class CleanupExpiredTokens extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tokens:cleanup';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up expired and revoked refresh tokens';

    protected JwtService $jwtService;

    /**
     * Create a new command instance.
     */
    public function __construct(JwtService $jwtService)
    {
        parent::__construct();
        $this->jwtService = $jwtService;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Cleaning up expired tokens...');

        $deletedCount = $this->jwtService->cleanupExpiredTokens();

        $this->info("Deleted {$deletedCount} expired/revoked tokens.");

        return 0;
    }
}
