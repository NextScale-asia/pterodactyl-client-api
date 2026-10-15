<?php

namespace Byzic\PterodactylClientApi;

use Illuminate\Support\ServiceProvider;

class PterodactylApiAddonServiceProvider extends ServiceProvider
{
    /**
     * Reported by GET /api/application/addon/info. Keep in sync with the release tag.
     */
    public const VERSION = '2.3.2';

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
