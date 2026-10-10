<?php

namespace Modules\Shift\Http\Controllers;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Modules\Shift\Exceptions\HandoverException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Maps handover domain failures without disclosing internal exception text. */
final class HandoverErrorResponse
{
    public static function from(\Throwable $error, string $operation): JsonResponse
    {
        if ($error instanceof ValidationException) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'code' => 'VALIDATION_ERROR',
                'errors' => $error->errors(),
            ], 422);
        }

        if ($error instanceof AccessDeniedHttpException || $error instanceof AuthorizationException) {
            return self::domain($error->getMessage() ?: 'FORBIDDEN_SCOPE', 403);
        }

        if ($error instanceof ConflictHttpException) {
            return self::domain($error->getMessage(), 409);
        }

        if ($error instanceof HandoverException) {
            return self::domain($error->getMessage(), $error->statusCode());
        }

        if ($error instanceof ModelNotFoundException) {
            return self::domain('HANDOVER_NOT_FOUND', 404);
        }

        Log::error('Handover operation failed', [
            'operation' => $operation,
            'exception' => $error,
        ]);

        return self::domain('Internal server error', 500);
    }

    public static function domain(string $message, int $status): JsonResponse
    {
        $code = preg_match('/\A[A-Z][A-Z0-9_]*\z/', $message)
            ? $message
            : match ($status) {
                403 => 'FORBIDDEN_SCOPE',
                409 => 'HANDOVER_CONFLICT',
                500 => 'INTERNAL_ERROR',
                default => 'HANDOVER_ERROR',
            };

        return response()->json(['success' => false, 'message' => $message, 'code' => $code], $status);
    }
}
