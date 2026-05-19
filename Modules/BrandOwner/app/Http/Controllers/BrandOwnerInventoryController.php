<?php

namespace Modules\BrandOwner\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\BranchManagers\Transformers\BmDailyInventoryDetailsResource;
use Modules\BranchManagers\Transformers\BmDailyInventoryListResource;
use Modules\BranchManagers\Transformers\BmWasteDamageDetailsResource;
use Modules\BranchManagers\Transformers\BmWasteDamageListResource;
use Modules\BrandOwner\Models\BrandOwner;
use Modules\BrandOwner\Services\BrandOwnerInventoryService;

class BrandOwnerInventoryController extends BaseController
{
    private const PER_PAGE = 20;

    public function __construct(
        private readonly BrandOwnerInventoryService $service,
    ) {}

    public function dailyIndex(Request $request): JsonResponse
    {
        if (!$this->resolveBrandOwner()) {
            return $this->forbiddenResponse('Only brand owners can access this resource.');
        }

        $paginator = $this->service->listDailyInventoryRequests(self::PER_PAGE);

        return $this->paginatedResponse(
            BmDailyInventoryListResource::collection($paginator),
            'Daily inventory requests retrieved successfully'
        );
    }

    public function wasteDamageIndex(Request $request): JsonResponse
    {
        if (!$this->resolveBrandOwner()) {
            return $this->forbiddenResponse('Only brand owners can access this resource.');
        }

        $paginator = $this->service->listWasteDamageRequests(self::PER_PAGE);

        return $this->paginatedResponse(
            BmWasteDamageListResource::collection($paginator),
            'Waste & damage requests retrieved successfully'
        );
    }

    public function dailyShow(string $requestId): JsonResponse
    {
        if (!$this->resolveBrandOwner()) {
            return $this->forbiddenResponse('Only brand owners can access this resource.');
        }

        try {
            $session = $this->service->getDailyInventoryDetails($requestId);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->notFoundResponse('Daily inventory request not found.');
        }

        return $this->successResponse(
            (new BmDailyInventoryDetailsResource($session))->resolve(),
            'Daily inventory details retrieved successfully'
        );
    }

    public function wasteDamageShow(string $requestId): JsonResponse
    {
        if (!$this->resolveBrandOwner()) {
            return $this->forbiddenResponse('Only brand owners can access this resource.');
        }

        try {
            $report = $this->service->getWasteDamageDetails($requestId);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->notFoundResponse('Waste & damage request not found.');
        }

        return $this->successResponse(
            (new BmWasteDamageDetailsResource($report))->resolve(),
            'Waste & damage details retrieved successfully'
        );
    }

    public function dailyApprove(string $requestId): JsonResponse
    {
        $owner = $this->resolveBrandOwner();
        if (!$owner) {
            return $this->forbiddenResponse('Only brand owners can access this resource.');
        }

        try {
            $session = $this->service->approveDailyInventory($owner, $requestId);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->notFoundResponse('Daily inventory request not found.');
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e->errors(), $e->getMessage());
        }

        return $this->successResponse($this->actionPayload($session->id, 'approved', $session->approved_at));
    }

    public function dailyReject(Request $request, string $requestId): JsonResponse
    {
        $owner = $this->resolveBrandOwner();
        if (!$owner) {
            return $this->forbiddenResponse('Only brand owners can access this resource.');
        }

        try {
            $session = $this->service->rejectDailyInventory($owner, $requestId, $request->input('reason'));
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->notFoundResponse('Daily inventory request not found.');
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e->errors(), $e->getMessage());
        }

        return $this->successResponse($this->actionPayload($session->id, 'rejected', $session->rejected_at));
    }

    public function wasteDamageApprove(string $requestId): JsonResponse
    {
        $owner = $this->resolveBrandOwner();
        if (!$owner) {
            return $this->forbiddenResponse('Only brand owners can access this resource.');
        }

        try {
            $report = $this->service->approveWasteDamage($owner, $requestId);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->notFoundResponse('Waste & damage request not found.');
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e->errors(), $e->getMessage());
        }

        return $this->successResponse($this->actionPayload($report->id, 'approved', $report->approved_at));
    }

    public function wasteDamageReject(Request $request, string $requestId): JsonResponse
    {
        $owner = $this->resolveBrandOwner();
        if (!$owner) {
            return $this->forbiddenResponse('Only brand owners can access this resource.');
        }

        try {
            $report = $this->service->rejectWasteDamage($owner, $requestId, $request->input('reason'));
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->notFoundResponse('Waste & damage request not found.');
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e->errors(), $e->getMessage());
        }

        return $this->successResponse($this->actionPayload($report->id, 'rejected', $report->rejected_at));
    }

    private function resolveBrandOwner(): ?BrandOwner
    {
        $user = auth()->user();
        return $user instanceof BrandOwner ? $user : null;
    }

    private function actionPayload(string $requestId, string $status, $processedAt): array
    {
        return [
            'request_id' => $requestId,
            'status' => $status,
            'processed_at' => $processedAt?->toIso8601String() ?? now()->toIso8601String(),
        ];
    }
}
