<?php

namespace PaymentGateway\EPS\Providers;

use Illuminate\Support\ServiceProvider;
use PaymentGateway\EPS\Services\EpsService;

class EpsServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Merge EPS configuration
        $this->mergeConfigFrom(__DIR__ . '/../Config/eps.php', 'eps');

        // Bind EpsService as a singleton in service container
        $this->app->singleton(EpsService::class, function ($app) {
            return new EpsService();
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Load EPS API & Web Routes
        if (file_exists($apiRoutes = __DIR__ . '/../Routes/api.php')) {
            $this->loadRoutesFrom($apiRoutes);
        }

        if (file_exists($webRoutes = __DIR__ . '/../Routes/web.php')) {
            $this->loadRoutesFrom($webRoutes);
        }

        // Load Blade Views
        if (is_dir($views = __DIR__ . '/../Views')) {
            $this->loadViewsFrom($views, 'eps');
        }

        // Publish configuration for artisan vendor:publish
        $this->publishes([
            __DIR__ . '/../Config/eps.php' => config_path('eps.php'),
        ], 'eps-config');
    }
}

