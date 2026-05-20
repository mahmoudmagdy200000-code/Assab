<?php

namespace Modules\BrandOwner\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Modules\BrandOwner\Models\BrandOwner;
use Modules\Purchase\Models\ReturnOrder;
use Modules\Purchase\Services\ReturnManagementService;
use Modules\Purchase\Transformers\ReturnOrderResource;

class BrandOwnerReturnController extends BaseController
{
    public function __construct(
        private readonly ReturnManagementService $returnService,
    ) {}

    public function approveEscalation(Request $request, string $returnId): JsonResponse
    {
        $owner = $this->resolveBrandOwner();
        if (! $owner) {
            return $this->forbiddenResponse('Only brand owners can access this resource.');
        }

        $validator = Validator::make($request->all(), [
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors()->toArray());
        }

        $return = ReturnOrder::find($returnId);
        if (! $return) {
            return $this->notFoundResponse('Return order not found');
        }

        try {
            $return = $this->returnService->approveEscalation($return, $owner->id, $validator->validated()['notes'] ?? null);
        } catch (\DomainException $e) {
            return $this->errorResponse($e->getMessage(), 400);
        } catch (\Exception $e) {
            return $this->handleException($e, 'approving escalation');
        }

        return $this->successResponse(
            new ReturnOrderResource($return->load(['purchaseOrder', 'supplier', 'items', 'timelines'])),
            'Escalation approved successfully'
        );
    }

    public function rejectEscalation(Request $request, string $returnId): JsonResponse
    {
        $owner = $this->resolveBrandOwner();
        if (! $owner) {
            return $this->forbiddenResponse('Only brand owners can access this resource.');
        }

        $validator = Validator::make($request->all(), [
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors()->toArray());
        }

        $return = ReturnOrder::find($returnId);
        if (! $return) {
            return $this->notFoundResponse('Return order not found');
        }

        try {
            $return = $this->returnService->rejectEscalation($return, $owner->id, $validator->validated()['reason']);
        } catch (\DomainException $e) {
            return $this->errorResponse($e->getMessage(), 400);
        } catch (\Exception $e) {
            return $this->handleException($e, 'rejecting escalation');
        }

        return $this->successResponse(
            new ReturnOrderResource($return->load(['purchaseOrder', 'supplier', 'items', 'timelines'])),
            'Escalation rejected successfully'
        );
    }

    private function resolveBrandOwner(): ?BrandOwner
    {
        $user = auth()->user();

        return $user instanceof BrandOwner ? $user : null;
    }
}
