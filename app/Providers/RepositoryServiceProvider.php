<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Interfaces\RideRepositoryInterface;
use App\Repositories\RideRepository;

class RepositoryServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->bind(
            RideRepositoryInterface::class,
            RideRepository::class
        );
    }
}
