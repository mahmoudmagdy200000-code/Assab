<?php

namespace Modules\Supplier\Http\Controllers;

use App\Http\Controllers\BaseController;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Modules\Supplier\Services\AnalyticsService;

class ReportingController extends BaseController
{
    public function __construct(
        private readonly AnalyticsService $analyticsService
    ) {}

    /**
     * Generate performance report
     */
    public function generatePerformanceReport(): JsonResponse
    {
        try {
            $supplier = auth()->user();
            $startDate = request()->get('start_date') ? Carbon::parse(request()->get('start_date')) : null;
            $endDate = request()->get('end_date') ? Carbon::parse(request()->get('end_date')) : null;

            $orderStats = $this->analyticsService->getOrderStatistics($supplier, $startDate, $endDate);
            $performanceMetrics = $this->analyticsService->getPerformanceMetrics($supplier);

            $report = array_merge($orderStats, $performanceMetrics);

            return $this->successResponse($report, 'Performance report generated successfully');
        } catch (\Exception $e) {
            return $this->handleException($e, 'generating performance report');
        }
    }

    /**
     * Generate financial report
     */
    public function generateFinancialReport(): JsonResponse
    {
        try {
            $supplier = auth()->user();
            $startDate = request()->get('start_date') ? Carbon::parse(request()->get('start_date')) : null;
            $endDate = request()->get('end_date') ? Carbon::parse(request()->get('end_date')) : null;

            $report = $this->analyticsService->getFinancialReport($supplier, $startDate, $endDate);

            return $this->successResponse($report, 'Financial report generated successfully');
        } catch (\Exception $e) {
            return $this->handleException($e, 'generating financial report');
        }
    }
}

