<?php

namespace App\Providers;

use App\Support\PetStore;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Share one in-memory store across every controller and request for the
        // lifetime of the process, so the demo behaves like a real persistence
        // layer without a database.
        $this->app->singleton(PetStore::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
