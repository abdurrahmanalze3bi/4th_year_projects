<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Interfaces\GeocodingServiceInterface;
use App\Services\OpenRouteService;

class GeocodingServiceProvider extends ServiceProvider
{
    // app/Providers/GeocodingServiceProvider.php
    public function register()
    {
        $this->app->singleton(GeocodingServiceInterface::class, function ($app) {
            return new OpenRouteService(
                config('services.openroute.key'),          // API key
                config('services.openroute.cache_ttl', 86400) // Cache TTL
            );
        });
    }
}
