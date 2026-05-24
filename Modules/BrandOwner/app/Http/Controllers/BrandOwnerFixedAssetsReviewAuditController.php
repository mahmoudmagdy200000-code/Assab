<?php

namespace Modules\BrandOwner\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Modules\BrandOwner\Models\BrandOwner;
use Modules\BrandOwner\Services\BrandOwnerFixedAssetsReviewAuditService;
use Modules\BrandOwner\Transformers\BrandOwnerFixedAssetsReviewAuditDetailsResource;
use Modules\BrandOwner\Transformers\BrandOwnerFixedAssetsReviewAuditListResource;

class BrandOwnerFixedAssetsReviewAuditController extends BaseController
{
    public function __construct(
        private readonly BrandOwnerFixedAssetsReviewAuditService $service,
    ) {}

    public function index(): JsonResponse
    {
        if (! $this->isBrandOwner()) {
            return $this->forbiddenResponse('Brand owner role required.');
        }

        return $this->successResponse(
            BrandOwnerFixedAssetsReviewAuditListResource::collection($this->service->list())->resolve(),
            'Review & audit requests retrieved successfully',
        );
    }

    public function show(string $id): JsonResponse
    {
        if (! $this->isBrandOwner()) {
            return $this->forbiddenResponse('Brand owner role required.');
        }

        try {
            $req = $this->service->find($id);
        } catch (ModelNotFoundException) {
            return $this->notFoundResponse('Review & audit request not found');
        }

        return $this->successResponse(
            (new BrandOwnerFixedAssetsReviewAuditDetailsResource($req))->toArray(request()),
            'Review & audit request details retrieved successfully',
        );
    }

    public function approve(string $id): JsonResponse
    {
        if (! $this->isBrandOwner()) {
            return $this->forbiddenResponse('Brand owner role required.');
        }

        try {
            $req = $this->service->approve($id, auth()->user());
        } catch (ModelNotFoundException) {
            return $this->notFoundResponse('Review & audit request not found');
        } catch (\Throwable $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }

        return $this->successResponse(
            [
                'data' => (new BrandOwnerFixedAssetsReviewAuditDetailsResource($req))->toArray(request()),
                'side_effects' => [],
            ],
            'Review & audit approved',
        );
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        if (! $this->isBrandOwner()) {
            return $this->forbiddenResponse('Brand owner role required.');
        }

        $validator = Validator::make($request->all(), [
            'reason' => 'required|string|min:3|max:500',
        ]);
        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        try {
            $req = $this->service->reject($id, auth()->user(), (string) $request->input('reason'));
        } catch (ModelNotFoundException) {
            return $this->notFoundResponse('Review & audit request not found');
        } catch (\Throwable $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }

        return $this->successResponse(
            [
                'data' => (new BrandOwnerFixedAssetsReviewAuditDetailsResource($req))->toArray(request()),
                'side_effects' => [],
            ],
            'Review & audit rejected',
        );
    }

    private function isBrandOwner(): bool
    {
        return auth()->user() instanceof BrandOwner;
    }
}
