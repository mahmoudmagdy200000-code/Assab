<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Log;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['middleware' => ['auth:sanctum,asab']],
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

        // Keep command identities permanently while pruning replayable bodies
        // after the documented response-retention window.
        $schedule->command('asab:idempotency-expire-responses')
            ->daily()
            ->at('04:20')
            ->timezone('Asia/Riyadh');

        // Drop FCM device tokens no app has used in months. Dead tokens are
        // also pruned reactively when Firebase rejects them, but a device that
        // is simply never opened again never produces a rejection.
        $schedule->command('notification:prune-device-tokens')
            ->weekly()
            ->mondays()
            ->at('04:15')
            ->timezone('Asia/Riyadh');

        // Generate weekly variance report
        $schedule->command('variance:weekly-report')
            ->weekly()
            ->sundays()
            ->at('23:00');

        // Renew shift week: create next work week shifts from current week pattern
        $schedule->command('shifts:renew-week')
            ->weekly()
            ->sundays()
            ->at('00:05')
            ->timezone('Asia/Riyadh');

        // Recurring orders are scheduled in RecurringOrderServiceProvider::registerCommandSchedules()
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [
            \App\Http\Middleware\ParseMultipartFormDataMiddleware::class,
        ]);

        $middleware->alias([
            'apilocale' => \App\Http\Middleware\ApiLocaleMiddleware::class,
            'localize' => \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRoutes::class,
            'localizationRedirect' => \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class,
            'localeSessionRedirect' => \Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class,
            'localeCookieRedirect' => \Mcamara\LaravelLocalization\Middleware\LocaleCookieRedirect::class,
            'localeViewPath' => \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationViewPath::class,
            'branch.manager' => \Modules\BranchManagers\Http\Middleware\BranchManagerMiddleware::class,
            'branch.manager.or.cashier' => \App\Http\Middleware\BranchManagerOrCashierMiddleware::class,
            'branch.manager.or.cashier.or.brand.owner' => \App\Http\Middleware\BranchManagerOrCashierOrBrandOwnerMiddleware::class,
            'branch.manager.or.brand.owner' => \App\Http\Middleware\BranchManagerOrBrandOwnerMiddleware::class,
            'cashier' => \Modules\Cashier\Http\Middleware\CashierMiddleware::class,
            'brand.owner' => \Modules\BrandOwner\Http\Middleware\BrandOwnerMiddleware::class,
            'supplier' => \Modules\Supplier\Http\Middleware\SupplierMiddleware::class,
            'asab.tenant' => \Modules\Admin\Http\Middleware\ResolveTenant::class,
            'asab.role' => \Modules\Admin\Http\Middleware\EnsureAsabRole::class,
            'asab.idempotency' => \Modules\Admin\Http\Middleware\IdempotencyKey::class,
            'asab.audit' => \Modules\Admin\Http\Middleware\AuditMutations::class,
            'asab.apikey' => \Modules\Admin\Http\Middleware\AuthenticateApiKey::class,
            'overload.shed' => \App\Http\Middleware\OverloadSheddingMiddleware::class,
            'log.throttle' => \App\Http\Middleware\LogThrottledRequestsMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // AsabController::run() only translates domain/validation/not-found
        // errors; anything else — a QueryException above all — escaped to
        // Laravel's default 500. Because no CORS headers ride on that response,
        // the browser reports it as a failed request rather than a server error,
        // which is why the dashboard shows «تعذر الاتصال بالخادم» for what is
        // really an unhandled exception. Keep the API envelope for API callers.
        // respond(), not render(): it runs on the ALREADY-RESOLVED response, so
        // the status is the one Laravel actually decided on. Filtering by
        // exception class instead means every framework exception that maps to a
        // status of its own — AuthenticationException at 401 the obvious one —
        // has to be enumerated, and the first one missed silently becomes a 500.
        $exceptions->respond(function (\Symfony\Component\HttpFoundation\Response $response, \Throwable $e, \Illuminate\Http\Request $request) {
            // Scoped to the ASAB dashboard surface: those routes answer in the
            // AsabResponse envelope, which is NOT the {success,message,data}
            // shape the legacy mobile controllers use.
            if ($response->getStatusCode() !== 500 || ! $request->is('api/v1/*')) {
                return $response;
            }

            return response()->json([
                'error' => [
                    'code' => 'SERVER_ERROR',
                    'message' => 'An unexpected server error occurred',
                    'messageAr' => 'حدث خطأ غير متوقع في الخادم',
                    // Never leak the message in production: a QueryException
                    // carries the SQL, the table names and the bound values.
                    'details' => config('app.debug')
                        ? ['exception' => $e::class, 'message' => $e->getMessage()]
                        : [],
                ],
                'requestId' => 'req_'.\Illuminate\Support\Str::upper(\Illuminate\Support\Str::random(13)),
            ], 500);
        });
    })
    ->withProviders([
        \App\Providers\ApiResponseServiceProvider::class,
    ])
    ->create();
