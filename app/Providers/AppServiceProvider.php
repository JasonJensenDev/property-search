<?php

namespace App\Providers;

use App\Support\DatabaseGuard;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Left to real requests: migrate, db:seed and the test suite all legitimately start
        // from an empty schema, and an empty-looking screen is the failure worth catching.
        if (! $this->app->runningInConsole()) {
            DatabaseGuard::verify();
        }
    }
}
