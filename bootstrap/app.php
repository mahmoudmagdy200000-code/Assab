<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule) {
        // Send shift reminders 15 minutes before shift starts
        $schedule->command('shifts:send-reminders')
            ->everyFifteenMinutes()
            ->between('8:00', '23:59');

        // Auto-end shifts that are overdue
        $schedule->command('shifts:auto-end-overdue')
            ->hourly()
            ->withoutOverlapping();

        // Generate daily shift reports
        $schedule->command('shifts:generate-daily-report')
            ->dailyAt('23:55')
            ->timezone('Asia/Riyadh');

        // Clean up old OTP records
        $schedule->command('otp:cleanup')
            ->daily()
            ->at('02:00');

        // Backup database
        $schedule->command('backup:run')
            ->daily()
            ->at('03:00')
            ->onFailure(function () {
                Log::error('Database backup failed');
            });

        // Clear expired notifications
        $schedule->command('notifications:cleanup')
            ->weekly()
            ->mondays()
            ->at('04:00');

        // Generate weekly variance report
        $schedule->command('variance:weekly-report')
            ->weekly()
            ->sundays()
            ->at('23:00');
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'apilocale' => \App\Http\Middleware\ApiLocaleMiddleware::class,
            'localize'              => \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRoutes::class,
            'localizationRedirect'  => \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class,
            'localeSessionRedirect' => \Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class,
            'localeCookieRedirect'  => \Mcamara\LaravelLocalization\Middleware\LocaleCookieRedirect::class,
            'localeViewPath'        => \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationViewPath::class,
            'branch.manager' => \Modules\BranchManagers\Http\Middleware\BranchManagerMiddleware::class,
            'cashier' => \Modules\Cashier\Http\Middleware\CashierMiddleware::class,
            'brand.owner' => \Modules\BrandOwners\Http\Middleware\BrandOwnerMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Exception handling configuration
        $exceptions->render(function (\Throwable $e) {
            // Global exception handling
        });
    })
    ->withProviders([
        \App\Providers\ApiResponseServiceProvider::class,
    ])
    ->create();
