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
     * Get balance trends for Balance Trend Stats UI.
     * GET /api/custody/balance-trends
     *
     * Query params:
     * - custodyType: 'branch' | 'personal' (default: branch)
     * - month: 1-12 (default: current month)
     * - year: e.g. 2026 (default: current year)
     * - granularity: 'daily' | 'weekly' | 'monthly' (default: daily)
     */
    public function getBalanceTrends(Request $request): JsonResponse
    {
        try {
            $filters = [
                'custodyType' => $request->input('custodyType', 'branch'),
                'month' => $request->input('month'),
                'year' => $request->input('year'),
                'granularity' => $request->input('granularity', 'daily'),
            ];

            $trends = $this->balanceService->getBalanceTrends(auth()->id(), $filters);

            return $this->successResponse($trends, 'Balance trends retrieved successfully');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }
}
