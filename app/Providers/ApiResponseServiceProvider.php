<?php

namespace App\Providers;

use Illuminate\Support\Facades\Response;
use Illuminate\Support\ServiceProvider;

class ApiResponseServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Register global response macros
        $this->registerResponseMacros();
    }

    /**
     * Register response macros for global use
     */
    protected function registerResponseMacros(): void
    {
        // Success response macro
        Response::macro('success', function ($data = null, string $message = 'Success', int $status = 200, array $meta = []) {
            $response = [
                'success' => true,
                'message' => $message,
                'data' => $data,
            ];

            if (! empty($meta)) {
                $response['meta'] = $meta;
            }

            return response()->json($response, $status);
        });

        // Error response macro
        Response::macro('error', function (string $message = 'Error', int $status = 400, $errors = null, array $meta = []) {
            $response = [
                'success' => false,
                'message' => $message,
            ];

            if ($errors !== null) {
                $response['errors'] = $errors;
            }

            if (! empty($meta)) {
                $response['meta'] = $meta;
            }

            return response()->json($response, $status);
        });

        // Validation error response macro
        Response::macro('validationError', function ($errors, string $message = 'Validation failed') {
            return Response::error($message, 422, $errors);
        });

        // Not found response macro
        Response::macro('notFound', function (string $message = 'Resource not found') {
            return Response::error($message, 404);
        });

        // Unauthorized response macro
        Response::macro('unauthorized', function (string $message = 'Unauthorized access') {
            return Response::error($message, 401);
        });

        // Forbidden response macro
        Response::macro('forbidden', function (string $message = 'Access forbidden') {
            return Response::error($message, 403);
        });

        // Server error response macro
        Response::macro('serverError', function (string $message = 'Internal server error') {
            return Response::error($message, 500);
        });

        // Created response macro
        Response::macro('created', function ($data = null, string $message = 'Resource created successfully') {
            return Response::success($data, $message, 201);
        });

        // Updated response macro
        Response::macro('updated', function ($data = null, string $message = 'Resource updated successfully') {
            return Response::success($data, $message, 200);
        });

        // Deleted response macro
        Response::macro('deleted', function (string $message = 'Resource deleted successfully') {
            return Response::success(null, $message, 200);
        });

        // Paginated response macro
        Response::macro('paginated', function ($data, string $message = 'Data retrieved successfully') {
            $meta = [
                'pagination' => [
                    'current_page' => $data->currentPage(),
                    'per_page' => $data->perPage(),
                    'total' => $data->total(),
                    'last_page' => $data->lastPage(),
                    'from' => $data->firstItem(),
                    'to' => $data->lastItem(),
                    'has_more_pages' => $data->hasMorePages(),
                ],
            ];

            return Response::success($data->items(), $message, 200, $meta);
        });
    }
}
