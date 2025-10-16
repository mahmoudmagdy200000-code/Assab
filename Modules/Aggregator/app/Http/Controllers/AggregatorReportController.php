<?php

namespace Modules\Aggregator\Http\Controllers;


use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Aggregator\Entities\Aggregator;
use Modules\Aggregator\Services\AggregatorReportService;

class AggregatorReportController extends Controller
{
    public function __construct(
        private AggregatorReportService $reportService
    ) {
        $this->middleware('auth:sanctum');
    }

    /**
     * Get sales report for aggregator
     */
    public function salesReport(Request $request, Aggregator $aggregator): JsonResponse
    {
        $period = $request->input('period', 'month');

        $report = $this->reportService->getSalesReport($aggregator, $period);

        return response()->success($report, 'Sales report retrieved successfully');
    }

    /**
     * Get sales breakdown by branch
     */
    public function salesByBranch(Request $request, Aggregator $aggregator): JsonResponse
    {
        $period = $request->input('period', 'month');

        $breakdown = $aggregator->getSalesBreakdownByBranch($period);

        return response()->success($breakdown, 'Sales breakdown by branch retrieved successfully');
    }

    /**
     * Get sales breakdown by date
     */
    public function salesByDate(Request $request, Aggregator $aggregator): JsonResponse
    {
        $period = $request->input('period', 'month');

        $breakdown = $aggregator->getSalesBreakdownByDate($period);

        return response()->success($breakdown, 'Sales breakdown by date retrieved successfully');
    }

    /**
     * Get commission report
     */
    public function commissionReport(Request $request, Aggregator $aggregator): JsonResponse
    {
        $period = $request->input('period', 'month');

        $report = $this->reportService->getCommissionReport($aggregator, $period);

        return response()->success($report, 'Commission report retrieved successfully');
    }

    /**
     * Get performance metrics
     */
    public function performanceMetrics(Request $request, Aggregator $aggregator): JsonResponse
    {
        $period = $request->input('period', 'month');

        $metrics = $this->reportService->getPerformanceMetrics($aggregator, $period);

        return response()->success($metrics, 'Performance metrics retrieved successfully');
    }
}
