<?php

namespace Modules\BranchManagers\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\BranchManagers\Services\DashboardService;
use Modules\BranchManagers\Traits\ApiResponseTrait;


class DashboardController extends Controller
{
    use ApiResponseTrait;
    public function __construct(
        private DashboardService $dashboardService
    ) {
        $this->middleware('auth:sanctum');
        $this->middleware('branch.manager');
    }

    /**
     * Get dashboard data
     */
    public function index(): JsonResponse
    {
        $manager = auth()->user();

        $dashboardData = $this->dashboardService->getDashboardData($manager);

        return $this->successResponse($dashboardData, 'Dashboard data retrieved successfully');
    }

    /**
     * Get today's summary
     */
    public function todaySummary(): JsonResponse
    {
        $manager = auth()->user();

        $summary = $this->dashboardService->getTodaySummary($manager);

        return $this->successResponse($summary, 'Today\'s summary retrieved successfully');
    }

    /**
     * Get quick statistics
     */
    public function quickStats(): JsonResponse
    {
        $manager = auth()->user();

        $stats = $this->dashboardService->getQuickStats($manager);

        return $this->successResponse($stats, 'Statistics retrieved successfully');
    }

    /**
     * Get recent activities
     */
    public function recentActivities(): JsonResponse
    {
        $manager = auth()->user();

        $activities = $this->dashboardService->getRecentActivities($manager);

        return $this->successResponse($activities, 'Recent activities retrieved successfully');
    }
}
