<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\AvatarService;
use App\Services\OpenAIAvatarService;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            AvatarService::class,
            OpenAIAvatarService::class
        );
    }

    public function boot(): void
    {
        //
    }
}
