<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        if ($this->app->environment('local')) {
            if (class_exists(\Laravel\Pail\PailServiceProvider::class)) {
                $this->app->register(\Laravel\Pail\PailServiceProvider::class);
            }
            if (class_exists(\Laravel\Sail\SailServiceProvider::class)) {
                $this->app->register(\Laravel\Sail\SailServiceProvider::class);
            }
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
