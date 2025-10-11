<?php

namespace Byzic\PterodactylClientApi;

use Illuminate\Support\ServiceProvider;
use Spatie\LaravelPackageTools\Package;

class PterodactylApiAddonServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any package services.
     *
     * @return void
     */
    public function boot()
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        
        // Publish config file
        $this->publishes([
            __DIR__.'/../config/pterodactyl-client-api.php' => config_path('pterodactyl-client-api.php'),
        ], 'config');
    }

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/pterodactyl-client-api.php', 'pterodactyl-client-api'
        );
    }
}
