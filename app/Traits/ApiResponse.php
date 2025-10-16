<?php

namespace App\Traits;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

trait ApiResponse
{
    /**
     * Success response format
     */
    protected function successResponse(
        $data = null,
        string $message = 'Success',
        int $status = 200,
        array $meta = []
    ): JsonResponse {
        $response = [
            'success' => true,
            'message' => $message,
            'data' => $data,
        ];

        if (!empty($meta)) {
            $response['meta'] = $meta;
        }

        return response()->json($response, $status);
    }

    /**
     * Error response format
     */
    protected function errorResponse(
        string $message = 'Error',
        int $status = 400,
        $errors = null,
        array $meta = []
    ): JsonResponse {
        $response = [
            'success' => false,
            'message' => $message,
        ];

        if ($errors !== null) {
            $response['errors'] = $errors;
        }

        if (!empty($meta)) {
            $response['meta'] = $meta;
        }

        return response()->json($response, $status);
    }

    /**
     * Validation error response
     */
    protected function validationErrorResponse(
        $errors,
        string $message = 'Validation failed'
    ): JsonResponse {
        return $this->errorResponse($message, 422, $errors);
    }

    /**
     * Not found response
     */
    protected function notFoundResponse(
        string $message = 'Resource not found'
    ): JsonResponse {
        return $this->errorResponse($message, 404);
    }

    /**
     * Unauthorized response
     */
    protected function unauthorizedResponse(
        string $message = 'Unauthorized access'
    ): JsonResponse {
        return $this->errorResponse($message, 401);
    }

    /**
     * Forbidden response
     */
    protected function forbiddenResponse(
        string $message = 'Access forbidden'
    ): JsonResponse {
        return $this->errorResponse($message, 403);
    }

    /**
     * Server error response
     */
    protected function serverErrorResponse(
        string $message = 'Internal server error',
        \Throwable $exception = null
    ): JsonResponse {
        if ($exception) {
            Log::error('Server Error: ' . $exception->getMessage(), [
                'exception' => $exception,
                'trace' => $exception->getTraceAsString()
            ]);
        }

        return $this->errorResponse($message, 500);
    }

    /**
     * Created response
     */
    protected function createdResponse(
        $data = null,
        string $message = 'Resource created successfully'
    ): JsonResponse {
        return $this->successResponse($data, $message, 201);
    }

    /**
     * Updated response
     */
    protected function updatedResponse(
        $data = null,
        string $message = 'Resource updated successfully'
    ): JsonResponse {
        return $this->successResponse($data, $message, 200);
    }

    /**
     * Deleted response
     */
    protected function deletedResponse(
        string $message = 'Resource deleted successfully'
    ): JsonResponse {
        return $this->successResponse(null, $message, 200);
    }

    /**
     * Paginated response
     */
    protected function paginatedResponse(
        $data,
        string $message = 'Data retrieved successfully'
    ): JsonResponse {
        // Support passing a ResourceCollection or a Paginator directly
        $paginator = method_exists($data, 'currentPage') ? $data : ($data->resource ?? null);

        if (!$paginator || !method_exists($paginator, 'currentPage')) {
            // Fallback to regular success response if not paginatable
            return $this->successResponse($data, $message);
        }

        $currentPage = $paginator->currentPage();
        $lastPage = $paginator->lastPage();

        // Build Laravel-style page links array
        $pageLinks = [];
        for ($i = 1; $i <= $lastPage; $i++) {
            $pageLinks[] = [
                'url' => $paginator->url($i),
                'label' => (string) $i,
                'active' => $i === $currentPage,
            ];
        }

        $links = [
            'first' => $paginator->url(1),
            'last' => $paginator->url($lastPage),
            'prev' => $paginator->previousPageUrl(),
            'next' => $paginator->nextPageUrl(),
        ];

        $meta = [
            'current_page' => $currentPage,
            'from' => $paginator->firstItem(),
            'last_page' => $lastPage,
            'links' => $pageLinks,
            'path' => $paginator->path(),
            'per_page' => $paginator->perPage(),
            'to' => $paginator->lastItem(),
            'total' => $paginator->total(),
        ];

        // When a ResourceCollection is passed, it already contains transformed items
        $items = method_exists($data, 'collection') ? $data->collection : $paginator->items();

        $response = [
            'success' => true,
            'message' => $message,
            'data' => $items,
            'links' => $links,
            'meta' => $meta,
        ];

        return response()->json($response, 200);
    }

    /**
     * Collection response
     */
    protected function collectionResponse(
        $data,
        string $message = 'Data retrieved successfully',
        array $meta = []
    ): JsonResponse {
        return $this->successResponse($data, $message, 200, $meta);
    }

    /**
     * Single resource response
     */
    protected function resourceResponse(
        $data,
        string $message = 'Resource retrieved successfully'
    ): JsonResponse {
        return $this->successResponse($data, $message, 200);
    }

    /**
     * No content response
     */
    protected function noContentResponse(
        string $message = 'No content'
    ): JsonResponse {
        return $this->successResponse(null, $message, 204);
    }

    /**
     * Conflict response
     */
    protected function conflictResponse(
        string $message = 'Conflict occurred'
    ): JsonResponse {
        return $this->errorResponse($message, 409);
    }

    /**
     * Too many requests response
     */
    protected function tooManyRequestsResponse(
        string $message = 'Too many requests'
    ): JsonResponse {
        return $this->errorResponse($message, 429);
    }

    /**
     * Service unavailable response
     */
    protected function serviceUnavailableResponse(
        string $message = 'Service temporarily unavailable'
    ): JsonResponse {
        return $this->errorResponse($message, 503);
    }

    /**
     * Handle exceptions in controllers
     */
    protected function handleException(\Throwable $exception, string $context = ''): JsonResponse
    {
        $message = $context ? "Error in {$context}" : 'An error occurred';

        return match (true) {
            $exception instanceof \Illuminate\Validation\ValidationException => $this->validationErrorResponse($exception->errors()),
            $exception instanceof \Illuminate\Database\Eloquent\ModelNotFoundException => $this->notFoundResponse('Resource not found'),
            $exception instanceof \Illuminate\Auth\Access\AuthorizationException => $this->forbiddenResponse('Access denied'),
            $exception instanceof \Illuminate\Auth\AuthenticationException => $this->unauthorizedResponse('Authentication required'),
            $exception instanceof \Illuminate\Database\QueryException => $this->handleDatabaseException($exception),
            default => $this->serverErrorResponse($message, $exception),
        };
    }

    /**
     * Handle database exceptions
     */
    private function handleDatabaseException(\Illuminate\Database\QueryException $exception): JsonResponse
    {
        Log::error('Database Error: ' . $exception->getMessage(), [
            'sql' => $exception->getSql(),
            'bindings' => $exception->getBindings(),
        ]);

        return $this->serverErrorResponse('Database error occurred');
    }

    /**
     * Format API response with consistent structure
     */
    protected function formatApiResponse(
        bool $success,
        $data = null,
        string $message = '',
        int $status = 200,
        array $meta = []
    ): JsonResponse {
        $response = [
            'success' => $success,
            'message' => $message,
        ];

        if ($data !== null) {
            $response['data'] = $data;
        }

        if (!empty($meta)) {
            $response['meta'] = $meta;
        }

        return response()->json($response, $status);
    }

    /**
     * Response with custom meta data
     */
    protected function responseWithMeta(
        $data,
        string $message = 'Success',
        array $meta = [],
        int $status = 200
    ): JsonResponse {
        return $this->successResponse($data, $message, $status, $meta);
    }

    /**
     * Response with execution time
     */
    protected function responseWithExecutionTime(
        $data,
        string $message = 'Success',
        float $startTime = null,
        int $status = 200
    ): JsonResponse {
        $meta = [];

        if ($startTime) {
            $meta['execution_time'] = round((microtime(true) - $startTime) * 1000, 2) . 'ms';
        }

        return $this->successResponse($data, $message, $status, $meta);
    }

    /**
     * Response with cache information
     */
    protected function responseWithCache(
        $data,
        string $message = 'Success',
        bool $cached = false,
        int $cacheTtl = null,
        int $status = 200
    ): JsonResponse {
        $meta = [
            'cached' => $cached,
        ];

        if ($cacheTtl) {
            $meta['cache_ttl'] = $cacheTtl;
        }

        return $this->successResponse($data, $message, $status, $meta);
    }
}
