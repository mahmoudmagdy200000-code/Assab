<?php

namespace App\Providers;

use App\Services\StreamUploadService;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Schema;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(StreamUploadService::class, fn () => new StreamUploadService);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
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
}
