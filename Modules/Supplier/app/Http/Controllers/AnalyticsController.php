<?php

namespace Modules\Supplier\Http\Controllers;

use App\Http\Controllers\BaseController;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Modules\Supplier\Services\AnalyticsService;

class AnalyticsController extends BaseController
{
    public function __construct(
        private readonly AnalyticsService $analyticsService
    ) {}

    /**
     * Get order statistics
     */
    public function getOrderStatistics(): JsonResponse
    {
        try {
            $supplier = auth()->user();
            $startDate = request()->get('start_date') ? Carbon::parse(request()->get('start_date')) : null;
            $endDate = request()->get('end_date') ? Carbon::parse(request()->get('end_date')) : null;

            $stats = $this->analyticsService->getOrderStatistics($supplier, $startDate, $endDate);

            return $this->successResponse($stats, 'Order statistics retrieved successfully');
        } catch (\Exception $e) {
            return $this->handleException($e, 'fetching order statistics');
        }
    }

    /**
     * Get financial report
     */
    public function getFinancialReport(): JsonResponse
    {
        try {
            $supplier = auth()->user();
            $startDate = request()->get('start_date') ? Carbon::parse(request()->get('start_date')) : null;
            $endDate = request()->get('end_date') ? Carbon::parse(request()->get('end_date')) : null;

            $report = $this->analyticsService->getFinancialReport($supplier, $startDate, $endDate);

            return $this->successResponse($report, 'Financial report retrieved successfully');
        } catch (\Exception $e) {
            return $this->handleException($e, 'fetching financial report');
        }
    }

    /**
     * Get performance metrics
     */
    public function getPerformanceMetrics(): JsonResponse
    {
        try {
            $supplier = auth()->user();
            $metrics = $this->analyticsService->getPerformanceMetrics($supplier);

            return $this->successResponse($metrics, 'Performance metrics retrieved successfully');
        } catch (\Exception $e) {
            return $this->handleException($e, 'fetching performance metrics');
        }
    }
}

