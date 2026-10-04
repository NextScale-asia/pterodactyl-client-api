<?php

namespace Byzic\PterodactylClientApi;

use Illuminate\Support\ServiceProvider;

class PterodactylApiAddonServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any package services.
     */
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__ . '/../routes/api.php');

        $this->publishes([
            __DIR__ . '/../config/pterodactyl-client-api.php' => config_path('pterodactyl-client-api.php'),
        ], 'config');
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/pterodactyl-client-api.php', 'pterodactyl-client-api'
        );
    }
}
