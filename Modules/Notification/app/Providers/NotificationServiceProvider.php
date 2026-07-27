<?php

namespace Modules\Notification\Providers;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\ServiceProvider;
use Modules\Notification\Console\Commands\PruneDeviceTokensCommand;
use Modules\Notification\Contracts\ChannelServiceInterface;
use Modules\Notification\Contracts\FcmClientInterface;
use Modules\Notification\Contracts\NotificationServiceInterface;
use Modules\Notification\Notifications\Channels\FcmChannel;
use Modules\Notification\Repositories\AudienceRepository;
use Modules\Notification\Repositories\AudienceRepositoryInterface;
use Modules\Notification\Repositories\DeviceTokenRepository;
use Modules\Notification\Repositories\DeviceTokenRepositoryInterface;
use Modules\Notification\Repositories\NotificationPreferenceRepository;
use Modules\Notification\Repositories\NotificationPreferenceRepositoryInterface;
use Modules\Notification\Repositories\NotificationRepository;
use Modules\Notification\Repositories\NotificationRepositoryInterface;
use Modules\Notification\Services\ChannelService;
use Modules\Notification\Services\Fcm\FcmAccessTokenProvider;
use Modules\Notification\Services\Fcm\FcmCredentials;
use Modules\Notification\Services\Fcm\FcmHttpV1Client;
use Modules\Notification\Services\Fcm\FcmMessageFactory;
use Modules\Notification\Services\Fcm\NullFcmClient;
use Modules\Notification\Services\NotificationCopyResolver;
use Modules\Notification\Services\NotificationService;
use Modules\Notification\Services\SmsProviders\SaudiTelecomSmsProvider;
use Modules\Notification\Services\SmsProviders\SmsProviderInterface;
use Psr\Log\LoggerInterface;

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
        $this->registerFcmNotificationChannel();

        if ($this->app->runningInConsole()) {
            $this->commands([
                PruneDeviceTokensCommand::class,
            ]);
        }
    }

    public function register(): void
    {
        $this->app->register(RouteServiceProvider::class);
        $this->app->register(EventServiceProvider::class);

        // Domain services
        $this->app->bind(NotificationServiceInterface::class, NotificationService::class);
        $this->app->bind(ChannelServiceInterface::class, ChannelService::class);
        $this->app->bind(SmsProviderInterface::class, SaudiTelecomSmsProvider::class);

        // Repositories
        $this->app->bind(NotificationPreferenceRepositoryInterface::class, NotificationPreferenceRepository::class);
        $this->app->bind(NotificationRepositoryInterface::class, NotificationRepository::class);
        $this->app->bind(DeviceTokenRepositoryInterface::class, DeviceTokenRepository::class);
        $this->app->bind(AudienceRepositoryInterface::class, AudienceRepository::class);

        $this->registerFcm();
    }

    /**
     * Firebase transport wiring.
     *
     * The driver defaults to `null` so the application boots and behaves
     * correctly with no Firebase credentials present — everything downstream of
     * the client (preferences, logging, token pruning) still runs.
     */
    protected function registerFcm(): void
    {
        $this->app->singleton(FcmCredentials::class, fn (Application $app) => new FcmCredentials(
            $app['config']->get('notification.fcm.credentials'),
            $app['config']->get('notification.fcm.project_id'),
        ));

        $this->app->singleton(FcmAccessTokenProvider::class, fn (Application $app) => new FcmAccessTokenProvider(
            $app->make(FcmCredentials::class),
            $app->make(CacheRepository::class),
            $app->make(HttpFactory::class),
            (int) $app['config']->get('notification.fcm.timeout', 10),
        ));

        $this->app->singleton(FcmMessageFactory::class, fn (Application $app) => new FcmMessageFactory(
            $app->make(NotificationCopyResolver::class),
            $app['config']->get('notification.fcm.android_channel_id'),
            (int) $app['config']->get('notification.fcm.ttl_seconds', 86400) ?: null,
        ));

        $this->app->singleton(FcmClientInterface::class, function (Application $app) {
            $config = $app['config'];
            $driver = $config->get('notification.fcm.driver', 'null');

            if ($driver !== 'http_v1') {
                return new NullFcmClient(
                    $app->make(LoggerInterface::class),
                    (bool) $config->get('notification.fcm.log_null_driver', true),
                );
            }

            return new FcmHttpV1Client(
                $app->make(FcmCredentials::class),
                $app->make(FcmAccessTokenProvider::class),
                $app->make(HttpFactory::class),
                (int) $config->get('notification.fcm.timeout', 10),
                $config->get('notification.fcm.android_channel_id'),
            );
        });
    }

    /**
     * Expose FCM as a first-class Laravel notification channel, so any module
     * can write `via(): ['database', 'fcm']` without touching this module's
     * service layer.
     */
    protected function registerFcmNotificationChannel(): void
    {
        $extend = fn (ChannelManager $manager) => $manager->extend(
            'fcm',
            fn ($app) => $app->make(FcmChannel::class)
        );

        $this->app->resolving(ChannelManager::class, $extend);

        // `resolving` only fires for future resolutions; if something already
        // pulled the manager during boot, register the driver on it now.
        if ($this->app->resolved(ChannelManager::class)) {
            $extend($this->app->make(ChannelManager::class));
        }
    }

    protected function registerConfig(): void
    {
        $this->publishes([
            module_path($this->moduleName, 'config/config.php') => config_path($this->moduleNameLower.'.php'),
        ], 'config');

        $this->mergeConfigFrom(
            module_path($this->moduleName, 'config/config.php'),
            $this->moduleNameLower
        );
    }

    protected function registerViews(): void
    {
        $viewPath = resource_path('views/modules/'.$this->moduleNameLower);
        $sourcePath = module_path($this->moduleName, 'resources/views');

        $this->publishes([
            $sourcePath => $viewPath,
        ], ['views', $this->moduleNameLower.'-module-views']);

        $this->loadViewsFrom(array_merge($this->getPublishableViewPaths(), [$sourcePath]), $this->moduleNameLower);
    }

    protected function registerTranslations(): void
    {
        $langPath = resource_path('lang/modules/'.$this->moduleNameLower);

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
            if (is_dir($path.'/modules/'.$this->moduleNameLower)) {
                $paths[] = $path.'/modules/'.$this->moduleNameLower;
            }
        }

        return $paths;
    }
}
