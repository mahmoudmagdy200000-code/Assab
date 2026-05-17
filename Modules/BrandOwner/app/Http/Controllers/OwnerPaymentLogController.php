<?php

namespace Modules\BrandOwner\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\BrandOwner\Models\BrandOwner;
use Modules\BrandOwner\Services\OwnerPaymentLogService;

class OwnerPaymentLogController extends BaseController
{
    public function __construct(
        private OwnerPaymentLogService $service
    ) {}

    /**
     * GET /api/brand-owner/payment-logs
     * Query: type, sortByDate, branchId, page, pageSize
     */
    public function index(Request $request): JsonResponse
    {
        $actor = auth()->user();
        if (!($actor instanceof BrandOwner)) {
            return $this->errorResponse('Only brand owners can view payment logs', 403);
        }

        $page     = max((int) $request->input('page', 1), 1);
        $pageSize = max(min((int) $request->input('pageSize', 20), 100), 1);

        $sortByDate = $request->input('sortByDate');
        if ($sortByDate && !in_array($sortByDate, ['last_24_hours', 'last_7_days', 'last_30_days', 'last_6_months'], true)) {
            return $this->errorResponse('Invalid sortByDate value', 400);
        }

        $payload = $this->service->listForBrandOwner($actor, [
            'type'       => $request->input('type'),
            'sortByDate' => $sortByDate,
            'branchId'   => $request->input('branchId'),
        ], $page, $pageSize);

        return $this->successResponse($payload, 'Payment logs retrieved successfully');
    }

    /**
     * GET /api/brand-owner/payment-logs/{paymentLogId}
     */
    public function show(string $paymentLogId): JsonResponse
    {
        $actor = auth()->user();
        if (!($actor instanceof BrandOwner)) {
            return $this->errorResponse('Only brand owners can view payment logs', 403);
        }

        $details = $this->service->findDetails($actor, $paymentLogId);
        if (!$details) {
            return $this->notFoundResponse('Payment log not found');
        }

        return $this->successResponse($details, 'Payment log retrieved successfully');
    }
}
