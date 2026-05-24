<?php

namespace Modules\BrandOwner\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Modules\BrandOwner\Models\BrandOwner;
use Modules\BrandOwner\Services\BrandOwnerFixedAssetsModificationService;
use Modules\BrandOwner\Transformers\BrandOwnerFixedAssetsModificationDetailsResource;
use Modules\BrandOwner\Transformers\BrandOwnerFixedAssetsModificationListResource;

class BrandOwnerFixedAssetsModificationController extends BaseController
{
    public function __construct(
        private readonly BrandOwnerFixedAssetsModificationService $service,
    ) {}

    public function index(): JsonResponse
    {
        if (! $this->isBrandOwner()) {
            return $this->forbiddenResponse('Brand owner role required.');
        }

        $data = $this->service->list();

        return $this->successResponse(
            BrandOwnerFixedAssetsModificationListResource::collection($data)->resolve(),
            'Modification requests retrieved successfully',
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
            return $this->notFoundResponse('Modification request not found');
        }

        return $this->successResponse(
            (new BrandOwnerFixedAssetsModificationDetailsResource($req))->toArray(request()),
            'Modification request details retrieved successfully',
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
            return $this->notFoundResponse('Modification request not found');
        } catch (\Throwable $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }

        return $this->successResponse(
            [
                'data' => (new BrandOwnerFixedAssetsModificationDetailsResource($req))->toArray(request()),
                'side_effects' => [],
            ],
            'Modification approved',
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
            return $this->notFoundResponse('Modification request not found');
        } catch (\Throwable $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }

        return $this->successResponse(
            [
                'data' => (new BrandOwnerFixedAssetsModificationDetailsResource($req))->toArray(request()),
                'side_effects' => [],
            ],
            'Modification rejected',
        );
    }

    private function isBrandOwner(): bool
    {
        return auth()->user() instanceof BrandOwner;
    }
}
