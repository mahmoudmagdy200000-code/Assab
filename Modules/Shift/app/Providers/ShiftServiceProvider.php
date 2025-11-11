<?php

namespace Modules\Shift\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Observers\CashierShiftObserver;
use Modules\Shift\Repositories\{
    CashierShiftRepository,
    CashierShiftRepositoryInterface,
    ShiftRepository,
    ShiftRepositoryInterface
};

class ShiftServiceProvider extends ServiceProvider
{
    protected string $moduleName = 'Shift';
    protected string $moduleNameLower = 'shift';

    public function boot(): void
    {
        $this->registerTranslations();
        $this->registerConfig();
        $this->registerViews();
        $this->loadMigrationsFrom(module_path($this->moduleName, 'database/migrations'));
        CashierShift::observe(CashierShiftObserver::class);
    }

    public function register(): void
    {
        $this->app->register(RouteServiceProvider::class);

        // Register Repositories
        $this->app->bind(
            CashierShiftRepositoryInterface::class,
            CashierShiftRepository::class
        );

        $this->app->bind(
            ShiftRepositoryInterface::class,
            ShiftRepository::class
        );
    }

    protected function registerConfig(): void
    {
        $this->publishes([
            module_path($this->moduleName, 'config/config.php') => config_path($this->moduleNameLower . '.php'),
        ], 'config');

        $this->mergeConfigFrom(
            module_path($this->moduleName, 'config/config.php'), $this->moduleNameLower
        );
    }

    protected function registerViews(): void
    {
        $viewPath = resource_path('views/modules/' . $this->moduleNameLower);
        $sourcePath = module_path($this->moduleName, 'resources/views');

        $this->publishes([
            $sourcePath => $viewPath
        ], ['views', $this->moduleNameLower . '-module-views']);

        $this->loadViewsFrom(array_merge($this->getPublishableViewPaths(), [$sourcePath]), $this->moduleNameLower);
    }

    protected function registerTranslations(): void
    {
        $langPath = resource_path('lang/modules/' . $this->moduleNameLower);

        if (is_dir($langPath)) {
            $this->loadTranslationsFrom($langPath, $this->moduleNameLower);
        } else {
            $this->loadTranslationsFrom(module_path($this->moduleName, 'resources/lang'), $this->moduleNameLower);
        }
    }

    protected function getPublishableViewPaths(): array
    {
        $paths = [];
        foreach (config('view.paths') as $path) {
            if (is_dir($path . '/modules/' . $this->moduleNameLower)) {
                $paths[] = $path . '/modules/' . $this->moduleNameLower;
            }
        }
        return $paths;
    }

    public function provides(): array
    {
        return [];
    }
}
