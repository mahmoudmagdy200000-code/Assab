<?php

namespace Modules\Admin\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

/**
 * Spec-exact response envelopes for the ASAB API (BACKEND_API_SPEC.md §2).
 *
 * Success (single)  : the bare resource object/array.
 * Success (list)    : { "data": [...], "meta": { page, pageSize, total, totalPages } }
 * Error             : { "error": { code, message, messageAr, details }, "requestId": "req_..." }
 *
 * This intentionally differs from app/Http/Controllers/BaseController.php because the
 * already-built frontend reads these exact shapes (login tokens at top level,
 * error.code, etc.).
 */
trait AsabResponse
{
    /** Return a bare resource (object/array) — used for single GET/POST/PATCH. */
    protected function ok($data = null, int $status = 200): JsonResponse
    {
        return response()->json($data, $status);
    }

    protected function created($data = null): JsonResponse
    {
        return response()->json($data, 201);
    }

    protected function noContent(): JsonResponse
    {
        return response()->json(null, 204);
    }

    /**
     * Paginated list envelope. Pass already-transformed $items (camelCase arrays);
     * falls back to the paginator's raw items.
     */
    protected function paginated(LengthAwarePaginator $paginator, ?array $items = null, array $extraMeta = []): JsonResponse
    {
        return response()->json([
            'data' => $items ?? $paginator->items(),
            'meta' => array_merge([
                'page' => $paginator->currentPage(),
                'pageSize' => $paginator->perPage(),
                'total' => $paginator->total(),
                'totalPages' => $paginator->lastPage(),
            ], $extraMeta),
        ]);
    }

    /** Simple (non-paginated) list with optional meta. */
    protected function listResponse(array $data, array $meta = []): JsonResponse
    {
        $payload = ['data' => $data];
        if (! empty($meta)) {
            $payload['meta'] = $meta;
        }

        return response()->json($payload);
    }

    /** Spec error envelope. */
    protected function fail(
        string $code,
        string $message,
        ?string $messageAr = null,
        array $details = [],
        int $status = 400
    ): JsonResponse {
        $error = ['code' => $code, 'message' => $message];
        if ($messageAr !== null) {
            $error['messageAr'] = $messageAr;
        }
        if (! empty($details)) {
            $error['details'] = $details;
        }

        return response()->json([
            'error' => $error,
            'requestId' => 'req_'.Str::upper(Str::random(13)),
        ], $status);
    }
}
