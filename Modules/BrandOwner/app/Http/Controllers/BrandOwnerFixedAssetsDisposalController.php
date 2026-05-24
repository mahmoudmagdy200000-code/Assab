<?php

namespace Modules\BrandOwner\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Modules\BrandOwner\Models\BrandOwner;
use Modules\BrandOwner\Services\BrandOwnerFixedAssetsDisposalService;
use Modules\BrandOwner\Transformers\BrandOwnerFixedAssetsDisposalDetailsResource;
use Modules\BrandOwner\Transformers\BrandOwnerFixedAssetsDisposalListResource;

class BrandOwnerFixedAssetsDisposalController extends BaseController
{
    public function __construct(
        private readonly BrandOwnerFixedAssetsDisposalService $service,
    ) {}

    public function index(): JsonResponse
    {
        if (! $this->isBrandOwner()) {
            return $this->forbiddenResponse('Brand owner role required.');
        }

        return $this->successResponse(
            BrandOwnerFixedAssetsDisposalListResource::collection($this->service->list())->resolve(),
            'Disposal & external transfer requests retrieved successfully',
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
            return $this->notFoundResponse('Disposal request not found');
        }

        return $this->successResponse(
            (new BrandOwnerFixedAssetsDisposalDetailsResource($item))->toArray(request()),
            'Disposal request details retrieved successfully',
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
            return $this->notFoundResponse('Disposal request not found');
        } catch (\Throwable $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }

        return $this->successResponse(
            [
                'data' => (new BrandOwnerFixedAssetsDisposalDetailsResource($item))->toArray(request()),
                'side_effects' => [],
            ],
            'Disposal approved',
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
            return $this->notFoundResponse('Disposal request not found');
        } catch (\Throwable $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }

        return $this->successResponse(
            [
                'data' => (new BrandOwnerFixedAssetsDisposalDetailsResource($item))->toArray(request()),
                'side_effects' => [],
            ],
            'Disposal rejected',
        );
    }

    private function isBrandOwner(): bool
    {
        return auth()->user() instanceof BrandOwner;
    }
}
