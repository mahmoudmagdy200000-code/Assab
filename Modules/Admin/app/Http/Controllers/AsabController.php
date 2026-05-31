<?php

namespace Modules\Admin\Http\Controllers;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;
use Modules\Admin\Exceptions\AsabException;
use Modules\Admin\Support\AsabResponse;

/**
 * Base controller for every ASAB endpoint. Thin HTTP layer only — emits the
 * spec-exact envelopes via AsabResponse. Business logic lives in Services.
 */
abstract class AsabController extends Controller
{
    use AuthorizesRequests, AsabResponse;

    /**
     * Run a controller action, translating domain/validation/not-found errors
     * into the spec's error envelope (BACKEND_API_SPEC.md §2).
     */
    protected function run(callable $fn): JsonResponse
    {
        try {
            return $fn();
        } catch (AsabException $e) {
            return $this->fail($e->errorCode, $e->getMessage(), $e->messageAr, $e->details, $e->status);
        } catch (ValidationException $e) {
            return $this->fail('VALIDATION_ERROR', 'Validation failed', 'فشل التحقق من البيانات', $e->errors(), 422);
        } catch (ModelNotFoundException $e) {
            return $this->fail('NOT_FOUND', 'Resource not found', 'العنصر غير موجود', [], 404);
        }
    }
}
