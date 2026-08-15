<?php

namespace App\Providers;

use App\Services\DenormalizedSlotService;
use App\Services\DynamicSlotService;
use App\Services\SlotServiceInterface;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(SlotServiceInterface::class, function () {
            return match (config('slots.provider')) {
                'dynamic' => new DynamicSlotService(),
                default => new DenormalizedSlotService(),
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
