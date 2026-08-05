<?php

namespace Modules\Admin\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use Modules\Admin\Console\Commands\CheckExpiringSubscriptions;
use Nwidart\Modules\Traits\PathNamespace;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class AdminServiceProvider extends ServiceProvider
{
    use PathNamespace;

    protected string $name = 'Admin';

    protected string $nameLower = 'admin';

    /**
     * Boot the application events.
     */
    public function boot(): void
    {
        $this->registerCommands();
        $this->registerCommandSchedules();
        $this->registerTranslations();
        $this->registerConfig();
        $this->registerViews();
        $this->loadMigrationsFrom(module_path($this->name, 'database/migrations'));
    }

    /**
     * Register the service provider.
     */
    public function register(): void
    {
        $this->app->register(EventServiceProvider::class);
        $this->app->register(RouteServiceProvider::class);

        // ASAB tenant context is resolved per-request and shared across the container.
        $this->app->scoped(\Modules\Admin\Support\TenantContext::class);
        // Branch-scope resolution memoizes per request; must share one instance.
        $this->app->scoped(\Modules\Admin\Services\TenantBranchResolver::class);

        // FR-PUR-1 «إرسال للمورد» — the dashboard depends on the notifier
        // abstraction; WhatsApp click-to-chat is today's provider. Rebind to a
        // gateway-backed notifier to enable automated send without call-site edits.
        $this->app->bind(
            \Modules\Admin\Services\Notifications\SupplierOrderNotifier::class,
            \Modules\Admin\Services\Notifications\WhatsAppSupplierNotifier::class,
        );

        $this->registerCredentialSync();
    }

    /**
     * One password, both worlds. The two tagged sets are the extension points:
     * a new dual-written role is a provisioner + a tag entry, and a new legacy
     * auth stack is a peer + a tag entry — neither touches a call site.
     */
    private function registerCredentialSync(): void
    {
        $this->app->tag([
            \Modules\Admin\Services\Credentials\BrandOwnerPeer::class,
            \Modules\Admin\Services\Credentials\SupplierPeer::class,
            \Modules\Admin\Services\Credentials\BranchManagerPeer::class,
        ], 'asab.credential-peers');

        $this->app->singleton(
            \Modules\Admin\Services\Credentials\CredentialPeerRegistry::class,
            fn ($app) => new \Modules\Admin\Services\Credentials\CredentialPeerRegistry($app->tagged('asab.credential-peers')),
        );

        $this->app->tag([
            \Modules\Admin\Services\Provisioning\SupplierUserProvisioner::class,
            \Modules\Admin\Services\Provisioning\BranchManagerProvisioner::class,
        ], 'asab.legacy-provisioners');

        $this->app->singleton(
            \Modules\Admin\Services\Provisioning\LegacyProvisionerRegistry::class,
            fn ($app) => new \Modules\Admin\Services\Provisioning\LegacyProvisionerRegistry($app->tagged('asab.legacy-provisioners')),
        );
    }

    /**
     * Register commands in the format of Command::class
     */
    protected function registerCommands(): void
    {
        $this->commands([
            CheckExpiringSubscriptions::class,
            \Modules\Admin\Console\Commands\MarkLateShiftsCommand::class,
            \Modules\Admin\Console\Commands\BackfillIdentityMap::class,
            \Modules\Admin\Console\Commands\MirrorMobileCashiers::class,
            \Modules\Admin\Console\Commands\BridgeBackfillCommand::class,
            \Modules\Admin\Console\Commands\DemoTopUpCommand::class,
            \Modules\Admin\Console\Commands\SyncManagerBranchesCommand::class,
            \Modules\Admin\Console\Commands\RepairUserCompaniesCommand::class,
        ]);
    }

    /**
     * Register command Schedules.
     */
    protected function registerCommandSchedules(): void
    {
        $this->app->booted(function () {
            $schedule = $this->app->make(Schedule::class);
            // Daily subscription-expiry sweep (BACKEND_API_SPEC.md §8 subscription.expiring)
            $schedule->command('asab:subscriptions-expiry')
                ->dailyAt('06:00')
                ->timezone('Asia/Riyadh');
            // ACC-6.2 — flag overdue open shifts as late.
            $schedule->command('asab:shifts-mark-late')
                ->everyFifteenMinutes()
                ->timezone('Asia/Riyadh');
        });
    }

    /**
     * Register translations.
     */
    public function registerTranslations(): void
    {
        $langPath = resource_path('lang/modules/'.$this->nameLower);

        if (is_dir($langPath)) {
            $this->loadTranslationsFrom($langPath, $this->nameLower);
            $this->loadJsonTranslationsFrom($langPath);
        } else {
            $this->loadTranslationsFrom(module_path($this->name, 'lang'), $this->nameLower);
            $this->loadJsonTranslationsFrom(module_path($this->name, 'lang'));
        }
    }

    /**
     * Register config.
     */
    protected function registerConfig(): void
    {
        $configPath = module_path($this->name, config('modules.paths.generator.config.path'));

        if (is_dir($configPath)) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($configPath));

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $config = str_replace($configPath.DIRECTORY_SEPARATOR, '', $file->getPathname());
                    $config_key = str_replace([DIRECTORY_SEPARATOR, '.php'], ['.', ''], $config);
                    $segments = explode('.', $this->nameLower.'.'.$config_key);

                    // Remove duplicated adjacent segments
                    $normalized = [];
                    foreach ($segments as $segment) {
                        if (end($normalized) !== $segment) {
                            $normalized[] = $segment;
                        }
                    }

                    $key = ($config === 'config.php') ? $this->nameLower : implode('.', $normalized);

                    $this->publishes([$file->getPathname() => config_path($config)], 'config');
                    $this->merge_config_from($file->getPathname(), $key);
                }
            }
        }
    }

    /**
     * Merge config from the given path recursively.
     */
    protected function merge_config_from(string $path, string $key): void
    {
        $existing = config($key, []);
        $module_config = require $path;

        config([$key => array_replace_recursive($existing, $module_config)]);
    }

    /**
     * Register views.
     */
    public function registerViews(): void
    {
        $viewPath = resource_path('views/modules/'.$this->nameLower);
        $sourcePath = module_path($this->name, 'resources/views');

        $this->publishes([$sourcePath => $viewPath], ['views', $this->nameLower.'-module-views']);

        $this->loadViewsFrom(array_merge($this->getPublishableViewPaths(), [$sourcePath]), $this->nameLower);

        Blade::componentNamespace(config('modules.namespace').'\\'.$this->name.'\\View\\Components', $this->nameLower);
    }

    /**
     * Get the services provided by the provider.
     */
    public function provides(): array
    {
        return [];
    }

    private function getPublishableViewPaths(): array
    {
        $paths = [];
        foreach (config('view.paths') as $path) {
            if (is_dir($path.'/modules/'.$this->nameLower)) {
                $paths[] = $path.'/modules/'.$this->nameLower;
            }
        }

        return $paths;
    }
}
