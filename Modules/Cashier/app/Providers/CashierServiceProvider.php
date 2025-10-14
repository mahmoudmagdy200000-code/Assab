<?php

namespace Modules\Cashier\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Gate;
use Modules\Cashier\Models\Cashier;
use Modules\Cashier\Observers\CashierObserver;
use Modules\Cashier\Policies\CashierPolicy;
use Modules\Cashier\Repositories\{
    CashierRepository,
    CashierRepositoryInterface
};

class CashierServiceProvider extends ServiceProvider
{
    protected string $moduleName = 'Cashier';
    protected string $moduleNameLower = 'cashier';

    public function boot(): void
    {
        $this->registerTranslations();
        $this->registerConfig();
        $this->registerViews();
        $this->loadMigrationsFrom(module_path($this->moduleName, 'Database/Migrations'));
        $this->registerPolicies();
        $this->registerObservers();

        if ($this->app->runningInConsole()) {
            $this->commands([
                \Modules\Cashier\Console\SendPendingActivationRemindersCommand::class,

            ]);
        }
    }

    protected function registerObservers(): void
    {
        Cashier::observe(CashierObserver::class);
    }

    public function register(): void
    {
        $this->app->register(RouteServiceProvider::class);
        $this->app->register(EventServiceProvider::class);

        // Register Repositories
        $this->app->bind(
            CashierRepositoryInterface::class,
            CashierRepository::class
        );
    }

    protected function registerConfig(): void
    {
        $this->publishes([
            module_path($this->moduleName, 'Config/config.php') => config_path($this->moduleNameLower . '.php'),
        ], 'config');

        $this->mergeConfigFrom(
            module_path($this->moduleName, 'Config/config.php'),
            $this->moduleNameLower
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

    protected function registerPolicies(): void
    {
        Gate::policy(Cashier::class, CashierPolicy::class);
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
