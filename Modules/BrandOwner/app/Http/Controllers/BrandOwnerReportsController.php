<?php

namespace Modules\BrandOwner\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\BrandOwner\Http\Requests\BrandOwnerExpenseReportFilterRequest;
use Modules\BrandOwner\Http\Requests\ExportCustodyReportRequest;
use Modules\BrandOwner\Http\Requests\ExportExpenseReportRequest;
use Modules\BrandOwner\Models\BrandOwner;
use Modules\BrandOwner\Services\BrandOwnerReportsService;

/**
 * Brand Owner Reports & Analytics screen.
 *
 * Routes are already guarded by the `brand.owner` middleware, so the
 * authenticated user is guaranteed to be a BrandOwner here.
 */
class BrandOwnerReportsController extends BaseController
{
    public function __construct(
        private BrandOwnerReportsService $service
    ) {}

    /**
     * GET /brand-owner/reports-and-analytics
     */
    public function index(): JsonResponse
    {
        /** @var BrandOwner $owner */
        $owner = auth()->user();

        return $this->successResponse(
            $this->service->getReportsAndAnalytics($owner),
            'Reports and analytics retrieved successfully'
        );
    }

    /**
     * GET /brand-owner/reports/expense/{reportId}
     */
    public function expenseDetails(BrandOwnerExpenseReportFilterRequest $request, string $reportId): JsonResponse
    {
        return $this->successResponse(
            $this->service->getExpenseReportDetails($reportId, $request->filters()),
            'Expense report retrieved successfully'
        );
    }

    /**
     * GET /brand-owner/reports/custody/{reportId}
     */
    public function custodyDetails(Request $request, string $reportId): JsonResponse
    {
        $month = $request->filled('month') ? (int) $request->input('month') : null;
        $year = $request->filled('year') ? (int) $request->input('year') : null;

        return $this->successResponse(
            $this->service->getCustodyReportDetails($reportId, $month, $year),
            'Custody report retrieved successfully'
        );
    }

    /**
     * POST /brand-owner/reports/expense/export
     */
    public function exportExpense(ExportExpenseReportRequest $request): JsonResponse
    {
        /** @var BrandOwner $owner */
        $owner = auth()->user();

        return $this->successResponse(
            $this->service->exportExpenseReport($owner, $request->validated()),
            'Expense report exported successfully'
        );
    }

    /**
     * POST /brand-owner/reports/custody/export
     */
    public function exportCustody(ExportCustodyReportRequest $request): JsonResponse
    {
        /** @var BrandOwner $owner */
        $owner = auth()->user();

        return $this->successResponse(
            $this->service->exportCustodyReport($owner, $request->validated()),
            'Custody report exported successfully'
        );
    }
}
