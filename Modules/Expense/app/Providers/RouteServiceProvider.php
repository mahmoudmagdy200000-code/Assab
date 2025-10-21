<?php

namespace Modules\Expense\Providers;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The module namespace to assume when generating URLs.
     */
    protected string $moduleNamespace = 'Modules\Expense\Http\Controllers';

    /**
     * Boot method
     */
    public function boot(): void
    {
        parent::boot();
    }

    /**
     * Map all module routes
     */
    public function map(): void
    {
        $this->mapApiRoutes();
        $this->mapWebRoutes();
    }

    /**
     * Define the "web" routes for the module.
     */
    protected function mapWebRoutes(): void
    {
        Route::middleware('web')
            ->namespace($this->moduleNamespace)
            ->group(module_path('Expense', 'routes/web.php'));
    }

    /**
     * Define the "api" routes for the module.
     */
    protected function mapApiRoutes(): void
    {
        Route::prefix('api/v1')
            ->middleware('api')
            ->namespace($this->moduleNamespace)
            ->group(module_path('Expense', 'routes/api.php'));
    }
}
