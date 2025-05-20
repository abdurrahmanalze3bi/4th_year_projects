<?php

namespace App\Providers;

use App\Services\OpenRouteService;
use Illuminate\Support\ServiceProvider;

class OpenRouteServiceProvider extends ServiceProvider
{
// app/Providers/OpenRouteServiceProvider.php
    public function register()
    {
        $this->app->singleton(OpenRouteService::class, function ($app) {
            $apiKey = config('services.openroute.api_key');

            if (empty($apiKey)) {
                throw new \RuntimeException('OpenRouteService API key not configured');
            }

            return new OpenRouteService($apiKey);
        });
    }
}
