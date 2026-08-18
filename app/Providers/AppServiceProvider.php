<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerBurstResilienceRateLimiters();

        // Success Response Macro
        Response::macro('success', function ($data = null, $message = 'Success', $statusCode = 200) {
            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => $data,
            ], $statusCode);
        });

        // Error Response Macro
        Response::macro('error', function ($message = 'Error', $statusCode = 400, $errors = null) {
            $response = [
                'success' => false,
                'message' => $message,
            ];

            if ($errors) {
                $response['errors'] = $errors;
            }

            return response()->json($response, $statusCode);
        });

        // Paginated Response Macro
        Response::macro('paginated', function ($data, $message = 'Data retrieved successfully') {
            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => $data->items(),
                'meta' => [
                    'current_page' => $data->currentPage(),
                    'last_page' => $data->lastPage(),
                    'per_page' => $data->perPage(),
                    'total' => $data->total(),
                    'from' => $data->firstItem(),
                    'to' => $data->lastItem(),
                ],
                'links' => [
                    'first' => $data->url(1),
                    'last' => $data->url($data->lastPage()),
                    'prev' => $data->previousPageUrl(),
                    'next' => $data->nextPageUrl(),
                ],
            ], 200);
        });

        Schema::defaultStringLength(191);
    }

    private function registerBurstResilienceRateLimiters(): void
    {
        RateLimiter::for('supplier-auth', function (Request $request) {
            return [
                Limit::perMinute(20)->by($request->ip()),
                Limit::perMinute(8)->by((string) $request->input('phone')),
            ];
        });

        // Same shape as `supplier-auth`: a per-IP ceiling plus a tighter one per
        // identifier, so one handset retrying cannot be used to walk the account
        // list. The cashier auth routes carried NO limiter at all until
        // 2026-08-15.
        RateLimiter::for('cashier-auth', function (Request $request) {
            return [
                Limit::perMinute(20)->by($request->ip()),
                Limit::perMinute(8)->by((string) $request->input('identifier')),
            ];
        });

        RateLimiter::for('purchase-write', function (Request $request) {
            return Limit::perMinute(40)->by((string) optional($request->user())->getAuthIdentifier() ?: $request->ip());
        });

        RateLimiter::for('expense-write', function (Request $request) {
            return Limit::perMinute(35)->by((string) optional($request->user())->getAuthIdentifier() ?: $request->ip());
        });

        RateLimiter::for('inventory-write', function (Request $request) {
            return Limit::perMinute(45)->by((string) optional($request->user())->getAuthIdentifier() ?: $request->ip());
        });

        RateLimiter::for('shift-write', function (Request $request) {
            return Limit::perMinute(50)->by((string) optional($request->user())->getAuthIdentifier() ?: $request->ip());
        });

        RateLimiter::for('custody-write', function (Request $request) {
            return Limit::perMinute(25)->by((string) optional($request->user())->getAuthIdentifier() ?: $request->ip());
        });
    }
}
