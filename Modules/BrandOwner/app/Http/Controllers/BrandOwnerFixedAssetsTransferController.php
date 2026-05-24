<?php

namespace Modules\BrandOwner\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Modules\BrandOwner\Models\BrandOwner;
use Modules\BrandOwner\Services\BrandOwnerFixedAssetsTransferService;
use Modules\BrandOwner\Transformers\BrandOwnerFixedAssetsTransferDetailsResource;
use Modules\BrandOwner\Transformers\BrandOwnerFixedAssetsTransferListResource;

class BrandOwnerFixedAssetsTransferController extends BaseController
{
    public function __construct(
        private readonly BrandOwnerFixedAssetsTransferService $service,
    ) {}

    public function index(): JsonResponse
    {
        if (! $this->isBrandOwner()) {
            return $this->forbiddenResponse('Brand owner role required.');
        }

        return $this->successResponse(
            BrandOwnerFixedAssetsTransferListResource::collection($this->service->list())->resolve(),
            'Transfer requests retrieved successfully',
        );
    }

    public function show(string $id): JsonResponse
    {
        if (! $this->isBrandOwner()) {
            return $this->forbiddenResponse('Brand owner role required.');
        }

        try {
            $item = $this->service->find($id);
        } catch (ModelNotFoundException) {
            return $this->notFoundResponse('Transfer request not found');
        }

        return $this->successResponse(
            (new BrandOwnerFixedAssetsTransferDetailsResource($item))->toArray(request()),
            'Transfer request details retrieved successfully',
        );
    }

    public function approve(string $id): JsonResponse
    {
        if (! $this->isBrandOwner()) {
            return $this->forbiddenResponse('Brand owner role required.');
        }

        try {
            $item = $this->service->approve($id, auth()->user());
        } catch (ModelNotFoundException) {
            return $this->notFoundResponse('Transfer request not found');
        } catch (\Throwable $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }

        return $this->successResponse(
            [
                'data' => (new BrandOwnerFixedAssetsTransferDetailsResource($item))->toArray(request()),
                'side_effects' => [],
            ],
            'Transfer approved',
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
            $item = $this->service->reject($id, auth()->user(), (string) $request->input('reason'));
        } catch (ModelNotFoundException) {
            return $this->notFoundResponse('Transfer request not found');
        } catch (\Throwable $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }

        return $this->successResponse(
            [
                'data' => (new BrandOwnerFixedAssetsTransferDetailsResource($item))->toArray(request()),
                'side_effects' => [],
            ],
            'Transfer rejected',
        );
    }

    private function isBrandOwner(): bool
    {
        return auth()->user() instanceof BrandOwner;
    }
}
