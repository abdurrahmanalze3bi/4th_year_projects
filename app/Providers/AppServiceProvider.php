<?php

namespace App\Providers;

use App\Interfaces\ChatRepositoryInterface;
use App\Interfaces\OtpRepositoryInterface;
use App\Interfaces\PasswordResetRepositoryInterface;
use App\Interfaces\ProfileRepositoryInterface;
use App\Interfaces\UserRepositoryInterface;
use App\Models\User;
use App\Observers\UserObserver;
use App\Repositories\ChatRepository;
use App\Repositories\PasswordResetRepository;
use App\Repositories\ProfileRepository;
use App\Repositories\UserRepository;
use App\Services\TextMeBotOtpService;
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
        \App\Interfaces\UserRepositoryInterface::class,
        \App\Repositories\UserRepository::class
    );

        $this->app->bind(
            \App\Interfaces\ProfileRepositoryInterface::class,
            \App\Repositories\ProfileRepository::class
        );

        // Add OTP repository binding
        $this->app->bind(
            \App\Interfaces\OtpRepositoryInterface::class,
            \App\Repositories\OtpRepository::class
        );
        $this->app->bind(
            \App\Interfaces\PhotoRepositoryInterface::class,
            \App\Repositories\PhotoRepository::class
        );
        $this->app->bind(ChatRepositoryInterface::class, ChatRepository::class);
        $this->app->bind(
            \App\Interfaces\VerificationRepositoryInterface::class,
            \App\Repositories\VerificationRepository::class
        );
        $this->app->bind(
            UserRepositoryInterface::class,
            UserRepository::class
        );
        $this->app->bind(TextMeBotOtpService::class, function ($app) {
            return new TextMeBotOtpService($app->make(OtpRepositoryInterface::class));
        });

        $this->app->bind(
            PasswordResetRepositoryInterface::class,
            PasswordResetRepository::class
        );
        $this->app->bind(
            ProfileRepositoryInterface::class,
            ProfileRepository::class
        );

    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Schema::defaultStringLength(191);
        User::observe(UserObserver::class);
    }


}
