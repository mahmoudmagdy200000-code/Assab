<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;

abstract class BaseController extends Controller
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

        // When a ResourceCollection is passed, resolve it to get transformed data
        if (method_exists($data, 'collection') && method_exists($data, 'toArray')) {
            try {
                $resolved = $data->toArray(request());
                $items = isset($resolved['data']) ? $resolved['data'] : array_values($resolved);
            } catch (\Throwable $e) {
                // Guard against "Attempt to read property 'map' on null" when collection is null
                if (str_contains($e->getMessage(), 'map') && str_contains($e->getMessage(), 'null')) {
                    $items = [];
                } else {
                    throw $e;
                }
            }
        } else {
            $items = $paginator->items();
        }

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
     * Handle exceptions in controllers
     */
    protected function handleException(\Throwable $exception, string $context = ''): JsonResponse
    {
        // Log the exception for debugging
        Log::error("Exception in {$context}", [
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
        ]);

        $message = $context ? "Error in {$context}" : 'An error occurred';

        return match (true) {
            $exception instanceof \Illuminate\Validation\ValidationException => $this->validationErrorResponse($exception->errors()),
            $exception instanceof \Illuminate\Database\Eloquent\ModelNotFoundException => $this->notFoundResponse('Resource not found'),
            $exception instanceof \Illuminate\Auth\Access\AuthorizationException => $this->forbiddenResponse('Access denied'),
            $exception instanceof \Illuminate\Auth\AuthenticationException => $this->unauthorizedResponse('Authentication required'),
            default => $this->errorResponse(
                $exception->getMessage() ?: $message,
                500,
                config('app.debug') ? [
                    'file' => $exception->getFile(),
                    'line' => $exception->getLine(),
                    'trace' => explode("\n", $exception->getTraceAsString()),
                ] : null
            ),
        };
    }
}
