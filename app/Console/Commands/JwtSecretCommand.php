<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class JwtSecretCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'jwt:secret
                            {--show : Display the key instead of modifying files}
                            {--force : Force the operation to run when in production}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Set the JWT secret key used to sign tokens';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $key = $this->generateRandomKey();

        if ($this->option('show')) {
            $this->line('<comment>' . $key . '</comment>');
            return 0;
        }

        // Next, we will replace the application key in the environment file.
        if (!$this->setKeyInEnvironmentFile($key)) {
            return 1;
        }

        $this->laravel['config']['jwt.secret'] = $key;

        $this->info('JWT secret key set successfully.');

        return 0;
    }

    /**
     * Generate a random key for the application.
     *
     * @return string
     */
    protected function generateRandomKey()
    {
        return base64_encode(Str::random(64));
    }

    /**
     * Set the application key in the environment file.
     *
     * @param  string  $key
     * @return bool
     */
    protected function setKeyInEnvironmentFile($key)
    {
        $currentKey = $this->laravel['config']['jwt.secret'];

        if (strlen($currentKey) !== 0 && (!$this->confirmToProceed())) {
            return false;
        }

        $this->writeNewEnvironmentFileWith($key);

        return true;
    }

    /**
     * Write a new environment file with the given key.
     *
     * @param  string  $key
     * @return void
     */
    protected function writeNewEnvironmentFileWith($key)
    {
        $envFile = $this->laravel->environmentFilePath();
        $contents = file_get_contents($envFile);

        // Try to replace existing JWT_SECRET
        if (preg_match('/^JWT_SECRET=.*$/m', $contents)) {
            $contents = preg_replace(
                '/^JWT_SECRET=.*$/m',
                'JWT_SECRET=' . $key,
                $contents
            );
        } else {
            // Add JWT_SECRET if it doesn't exist
            $contents .= "\nJWT_SECRET=" . $key;
        }

        file_put_contents($envFile, $contents);
    }

    /**
     * Confirm before proceeding with the action.
     *
     * This method only asks for confirmation in production.
     *
     * @return bool
     */
    protected function confirmToProceed()
    {
        if ($this->option('force')) {
            return true;
        }

        $this->alert('Application In Production!');

        return $this->confirm('Do you really wish to run this command?');
    }
}
