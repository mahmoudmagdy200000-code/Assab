<?php

namespace Modules\Purchase\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Database\Eloquent\Factory;

class PurchaseServiceProvider extends ServiceProvider
{
    /**
     * Boot the application events.
     */
    public function boot(): void
    {
        $this->registerTranslations();
        $this->registerConfig();
        $this->registerViews();
        $this->loadMigrationsFrom(module_path('Purchase', 'database/migrations'));
    }

    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->app->register(RouteServiceProvider::class);
    }

    /**
     * Register config.
     */
    protected function registerConfig(): void
    {
        $this->publishes([
            module_path('Purchase', 'config/config.php') => config_path('purchase.php'),
        ], 'purchase-config');

        $this->mergeConfigFrom(
            module_path('Purchase', 'config/config.php'), 'purchase'
        );
    }

    /**
     * Register views.
     */
    public function registerViews(): void
    {
        $viewPath = resource_path('views/modules/purchase');

        $sourcePath = module_path('Purchase', 'resources/views');

        $this->publishes([
            $sourcePath => $viewPath
        ], ['views', 'purchase-module-views']);

        $this->loadViewsFrom(array_merge([$sourcePath], config('view.paths')), 'purchase');
    }

    /**
     * Register translations.
     */
    public function registerTranslations(): void
    {
        $langPath = resource_path('lang/modules/purchase');

        if (is_dir($langPath)) {
            $this->loadTranslationsFrom($langPath, 'purchase');
        } else {
            $this->loadTranslationsFrom(module_path('Purchase', 'resources/lang'), 'purchase');
        }
    }

    /**
     * Get the services provided by the provider.
     */
    public function provides(): array
    {
        return [];
    }
}
