<?php

namespace App\Providers;

use App\Services\DenormalizedSlotService;
use App\Services\DynamicSlotService;
use App\Services\SlotCacheService;
use App\Services\SlotServiceInterface;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(SlotCacheService::class);

        $this->app->bind(SlotServiceInterface::class, function ($app) {
            return match (config('slots.provider')) {
                'dynamic' => $app->make(DynamicSlotService::class),
                default => $app->make(DenormalizedSlotService::class),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
