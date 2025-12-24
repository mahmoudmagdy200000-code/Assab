<?php

namespace Modules\Notification\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Notification\Contracts\ChannelServiceInterface;
use Modules\Notification\Contracts\NotificationServiceInterface;
use Modules\Notification\Repositories\NotificationPreferenceRepositoryInterface;
use Modules\Notification\Repositories\NotificationPreferenceRepository;
use Modules\Notification\Services\ChannelService;
use Modules\Notification\Services\NotificationService;
use Modules\Notification\Services\Channels\EmailChannelService;
use Modules\Notification\Services\Channels\SmsChannelService;
use Modules\Notification\Services\SmsProviders\SmsProviderInterface;
use Modules\Notification\Services\SmsProviders\SaudiTelecomSmsProvider;

class NotificationServiceProvider extends ServiceProvider
{
    protected string $moduleName = 'Notification';
    protected string $moduleNameLower = 'notification';

    public function boot(): void
    {
        $this->registerTranslations();
        $this->registerConfig();
        $this->registerViews();
        $this->loadMigrationsFrom(module_path($this->moduleName, 'database/migrations'));
    }

    public function register(): void
    {
        $this->app->register(RouteServiceProvider::class);
        $this->app->register(EventServiceProvider::class);

        // Bind interfaces to implementations
        $this->app->bind(NotificationServiceInterface::class, NotificationService::class);
        $this->app->bind(ChannelServiceInterface::class, ChannelService::class);
        $this->app->bind(NotificationPreferenceRepositoryInterface::class, NotificationPreferenceRepository::class);
        $this->app->bind(SmsProviderInterface::class, SaudiTelecomSmsProvider::class);
    }

    protected function registerConfig(): void
    {
        $this->publishes([
            module_path($this->moduleName, 'config/config.php') => config_path($this->moduleNameLower . '.php'),
        ], 'config');

        $this->mergeConfigFrom(
            module_path($this->moduleName, 'config/config.php'),
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
}

