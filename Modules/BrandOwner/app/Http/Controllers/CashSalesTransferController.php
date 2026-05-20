<?php

namespace Modules\BrandOwner\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Modules\BrandOwner\Models\BrandOwner;
use Modules\BrandOwner\Services\CashSalesTransferService;

class CashSalesTransferController extends BaseController
{
    public function __construct(
        private CashSalesTransferService $service
    ) {}

    /**
     * GET /api/brand-owner/cash-sales-transfers
     * Filters: fromDate, toDate, storeId, status, page, pageSize
     */
    public function index(Request $request): JsonResponse
    {
        $actor = auth()->user();
        if (! ($actor instanceof BrandOwner)) {
            return $this->errorResponse('Only brand owners can list cash sales transfers', 403);
        }

        $page = max((int) $request->input('page', 1), 1);
        $pageSize = max(min((int) $request->input('pageSize', 20), 100), 1);

        $status = $request->input('status');
        if ($status && ! in_array($status, ['pending', 'approved', 'rejected'], true)) {
            return $this->errorResponse('Invalid status. Must be: pending, approved, or rejected', 400);
        }

        $payload = $this->service->listForBrandOwner($actor->id, [
            'fromDate' => $request->input('fromDate'),
            'toDate' => $request->input('toDate'),
            'storeId' => $request->input('storeId'),
            'status' => $status,
        ], $page, $pageSize);

        return $this->successResponse($payload, 'Cash sales transfers retrieved successfully');
    }

    /**
     * GET /api/brand-owner/cash-sales-transfers/{id}
     */
    public function show(string $id): JsonResponse
    {
        try {
            $payload = $this->service->getDetails($id);

            return $this->successResponse($payload, 'Cash sales transfer details retrieved successfully');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->notFoundResponse('Cash sales transfer request not found');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * POST /api/brand-owner/cash-sales-transfers/{id}/approve
     */
    public function approve(string $id): JsonResponse
    {
        $actor = auth()->user();
        if (! ($actor instanceof BrandOwner)) {
            return $this->errorResponse('Only brand owners can approve cash sales transfers', 403);
        }

        try {
            $request = $this->service->approve($id, $actor);

            return $this->successResponse([
                'requestId' => $request->id,
                'status' => $request->status,
            ], 'Cash sales transfer approved');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->notFoundResponse('Cash sales transfer request not found');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }
    }

    /**
     * POST /api/brand-owner/cash-sales-transfers/{id}/reject
     */
    public function reject(Request $request, string $id): JsonResponse
    {
        $actor = auth()->user();
        if (! ($actor instanceof BrandOwner)) {
            return $this->errorResponse('Only brand owners can reject cash sales transfers', 403);
        }

        $validator = Validator::make($request->all(), [
            'reason' => 'required|string|min:3|max:500',
        ]);
        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        try {
            $req = $this->service->reject($id, $actor, $request->input('reason'));

            return $this->successResponse([
                'requestId' => $req->id,
                'status' => $req->status,
            ], 'Cash sales transfer rejected');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return $this->notFoundResponse('Cash sales transfer request not found');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }
    }
}
