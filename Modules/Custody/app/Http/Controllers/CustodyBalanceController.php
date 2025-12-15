<?php

namespace Modules\Custody\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Custody\Services\CustodyBalanceService;

class CustodyBalanceController extends BaseController
{
    public function __construct(
        private CustodyBalanceService $balanceService
    ) {}

    /**
     * Get balance trends
     * GET /api/custody/balance-trends
     */
    public function getBalanceTrends(Request $request): JsonResponse
    {
        try {
            $filters = [
                'period' => $request->input('period', 'today'),
                'granularity' => $request->input('granularity'),
            ];

            $trends = $this->balanceService->getBalanceTrends(auth()->id(), $filters);

            return $this->successResponse($trends, 'Balance trends retrieved successfully');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }
}
