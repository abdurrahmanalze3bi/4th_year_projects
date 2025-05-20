<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\OpenRouteService;
use App\Interfaces\GeocodingServiceInterface;

class GeocodingServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->singleton(GeocodingServiceInterface::class, function ($app) {
            return new OpenRouteService(
                config('services.openroute.api_key'),
                config('services.openroute.cache_ttl', 86400),
                config('services.openroute.ssl_verify', true),
                config('services.openroute.timeout', 30)
            );
        });
    }
}
