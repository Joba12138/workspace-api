<?php

namespace App\Providers;

use App\Contracts\LlmClient;
use App\Services\Llm\LlmManager;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(LlmManager::class);
        $this->app->bind(LlmClient::class, fn ($app) => $app->make(LlmManager::class)->driver());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
