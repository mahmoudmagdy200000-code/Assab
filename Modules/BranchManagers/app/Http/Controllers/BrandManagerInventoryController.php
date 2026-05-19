<?php

namespace Modules\BranchManagers\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\BranchManagers\Models\BranchManager;
use Modules\BranchManagers\Services\BrandManagerInventoryService;
use Modules\BranchManagers\Transformers\BmDailyInventoryDetailsResource;
use Modules\BranchManagers\Transformers\BmDailyInventoryListResource;
use Modules\BranchManagers\Transformers\BmWasteDamageDetailsResource;
use Modules\BranchManagers\Transformers\BmWasteDamageListResource;

class BrandManagerInventoryController extends BaseController
{
    public function __construct(
        private readonly BrandManagerInventoryService $service,
    ) {}

    public function dailyIndex(Request $request): JsonResponse
    {
        $manager = $this->resolveManager();
        if (!$manager) {
            return $this->forbiddenResponse('Only branch managers can access this resource.');
        }

        $perPage = $this->resolvePerPage($request);
        $status = $this->resolveStatus($request);
        if ($status === false) {
            return $this->errorResponse('Invalid status. Allowed: pending, completed.', 422);
        }

        $paginator = $this->service->listDailyInventoryRequests(
            $manager,
            $perPage,
            $status,
            $request->query('branch'),
        );

        return $this->paginatedResponse(
            BmDailyInventoryListResource::collection($paginator),
            'Daily inventory requests retrieved successfully'
        );
    }

    public function wasteDamageIndex(Request $request): JsonResponse
    {
        $manager = $this->resolveManager();
        if (!$manager) {
            return $this->forbiddenResponse('Only branch managers can access this resource.');
        }

        $perPage = $this->resolvePerPage($request);
        $status = $this->resolveStatus($request);
        if ($status === false) {
            return $this->errorResponse('Invalid status. Allowed: pending, completed.', 422);
        }

        $paginator = $this->service->listWasteDamageRequests(
            $manager,
            $perPage,
            $status,
            $request->query('branch'),
        );

        return $this->paginatedResponse(
            BmWasteDamageListResource::collection($paginator),
            'Waste & damage requests retrieved successfully'
        );
    }

    public function dailyShow(string $requestId): JsonResponse
    {
        $manager = $this->resolveManager();
        if (!$manager) {
            return $this->forbiddenResponse('Only branch managers can access this resource.');
        }

        try {
            $session = $this->service->getDailyInventoryDetails($manager, $requestId);
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
        $manager = $this->resolveManager();
        if (!$manager) {
            return $this->forbiddenResponse('Only branch managers can access this resource.');
        }

        try {
            $report = $this->service->getWasteDamageDetails($manager, $requestId);
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
        $manager = $this->resolveManager();
        if (!$manager) {
            return $this->forbiddenResponse('Only branch managers can access this resource.');
        }

        try {
            $session = $this->service->approveDailyInventory($manager, $requestId);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->notFoundResponse('Daily inventory request not found.');
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e->errors(), $e->getMessage());
        }

        return $this->successResponse($this->actionPayload($session->id, 'approved', $session->approved_at));
    }

    public function dailyReject(Request $request, string $requestId): JsonResponse
    {
        $manager = $this->resolveManager();
        if (!$manager) {
            return $this->forbiddenResponse('Only branch managers can access this resource.');
        }

        try {
            $session = $this->service->rejectDailyInventory(
                $manager,
                $requestId,
                $request->input('reason'),
            );
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->notFoundResponse('Daily inventory request not found.');
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e->errors(), $e->getMessage());
        }

        return $this->successResponse($this->actionPayload($session->id, 'rejected', $session->rejected_at));
    }

    public function wasteDamageApprove(string $requestId): JsonResponse
    {
        $manager = $this->resolveManager();
        if (!$manager) {
            return $this->forbiddenResponse('Only branch managers can access this resource.');
        }

        try {
            $report = $this->service->approveWasteDamage($manager, $requestId);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->notFoundResponse('Waste & damage request not found.');
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e->errors(), $e->getMessage());
        }

        return $this->successResponse($this->actionPayload($report->id, 'approved', $report->approved_at));
    }

    public function wasteDamageReject(Request $request, string $requestId): JsonResponse
    {
        $manager = $this->resolveManager();
        if (!$manager) {
            return $this->forbiddenResponse('Only branch managers can access this resource.');
        }

        try {
            $report = $this->service->rejectWasteDamage(
                $manager,
                $requestId,
                $request->input('reason'),
            );
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->notFoundResponse('Waste & damage request not found.');
        } catch (ValidationException $e) {
            return $this->validationErrorResponse($e->errors(), $e->getMessage());
        }

        return $this->successResponse($this->actionPayload($report->id, 'rejected', $report->rejected_at));
    }

    private function resolveManager(): ?BranchManager
    {
        $user = auth()->user();
        return $user instanceof BranchManager ? $user : null;
    }

    private function resolvePerPage(Request $request): int
    {
        $limit = (int) $request->query('limit', 20);
        if ($limit < 1) {
            $limit = 20;
        }
        return min($limit, 100);
    }

    /**
     * Returns normalized status string, null when omitted, or false on invalid input.
     */
    private function resolveStatus(Request $request): null|false|string
    {
        $raw = $request->query('status');
        if ($raw === null || $raw === '') {
            return null;
        }
        $value = strtolower((string) $raw);
        if (!in_array($value, ['pending', 'completed'], true)) {
            return false;
        }
        return $value;
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
