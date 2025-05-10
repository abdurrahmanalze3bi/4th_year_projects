<?php

namespace App\Providers;

use App\Interfaces\PasswordResetRepositoryInterface;
use App\Interfaces\UserRepositoryInterface;
use App\Repositories\PasswordResetRepository;
use App\Repositories\UserRepository;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(
            UserRepositoryInterface::class,
            UserRepository::class
        );

        $this->app->bind(
            PasswordResetRepositoryInterface::class,
            PasswordResetRepository::class
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Schema::defaultStringLength(191);
    }
}
