<?php

namespace Modules\BranchManagers\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\BranchManagers\Services\DashboardService;
use App\ApiResponse as ApiResponseTrait;

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

        return $this->successResponse('Dashboard data retrieved successfully',$dashboardData );
    }

    /**
     * Get today's summary
     */
    public function todaySummary(): JsonResponse
    {
        $manager = auth()->user();

        $summary = $this->dashboardService->getTodaySummary($manager);

        return $this->successResponse('Today\'s summary retrieved successfully',$summary );
    }

    /**
     * Get quick statistics
     */
    public function quickStats(): JsonResponse
    {
        $manager = auth()->user();

        $stats = $this->dashboardService->getQuickStats($manager);

        return $this->successResponse('Statistics retrieved successfully',$stats );
    }

    /**
     * Get recent activities
     */
    public function recentActivities(): JsonResponse
    {
        $manager = auth()->user();

        $activities = $this->dashboardService->getRecentActivities($manager);

        return $this->successResponse('Recent activities retrieved successfully',$activities );
    }
}
