<?php

namespace App;

use Illuminate\Http\JsonResponse;
use Throwable;

trait ApiResponse
{
    protected function successResponse($message = '', $data = null, $code = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $code);
    }

    protected function errorResponse($message = '', $code = 400, $errors = null): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => $errors,
        ], $code);
    }

    protected function handleException(Throwable $e, $defaultCode = 400): JsonResponse
    {
        return $this->errorResponse(
            $e->getMessage(),
            method_exists($e, 'getStatusCode') ? $e->getStatusCode() : $defaultCode
        );
    }
}
