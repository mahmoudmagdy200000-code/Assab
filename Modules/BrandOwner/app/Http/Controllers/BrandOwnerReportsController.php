<?php

namespace Modules\BrandOwner\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\BranchManagers\Models\BranchManager;
use Modules\BrandOwner\Http\Requests\BrandOwnerExpenseReportFilterRequest;
use Modules\BrandOwner\Http\Requests\ExportCustodyReportRequest;
use Modules\BrandOwner\Http\Requests\ExportExpenseReportRequest;
use Modules\BrandOwner\Models\BrandOwner;
use Modules\BrandOwner\Services\BrandOwnerReportsService;

/**
 * Brand Owner Reports & Analytics screen.
 *
 * The list, export and analytics endpoints are guarded by the `brand.owner`
 * middleware, so the authenticated user is a BrandOwner there.
 *
 * The expense / custody report-detail endpoints are also reachable by branch
 * managers (`branch.manager.or.brand.owner` middleware). A branch manager
 * always receives data scoped to their own branch; a brand owner sees all
 * branches exactly as before.
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
     *
     * Accessible to brand owners and branch managers. Branch managers are
     * locked to their own branch; brand owners may filter by any branch.
     */
    public function expenseDetails(BrandOwnerExpenseReportFilterRequest $request, string $reportId): JsonResponse
    {
        $user = auth()->user();

        if (! $user instanceof BrandOwner && ! $user instanceof BranchManager) {
            return $this->forbiddenResponse('Brand owner or branch manager access required.');
        }

        $filters = $request->filters();

        // Branch managers only ever see their own branch — ignore any
        // client-supplied branch_id and force tenant isolation.
        if ($user instanceof BranchManager) {
            $filters['branch_id'] = $user->branch_id;
            $filters['scope_branch_only'] = true;
        }

        return $this->successResponse(
            $this->service->getExpenseReportDetails($reportId, $filters),
            'Expense report retrieved successfully'
        );
    }

    /**
     * GET /brand-owner/reports/custody/{reportId}
     *
     * Accessible to brand owners and branch managers. Branch managers receive
     * only their own branch; brand owners receive every branch.
     */
    public function custodyDetails(Request $request, string $reportId): JsonResponse
    {
        $user = auth()->user();

        if (! $user instanceof BrandOwner && ! $user instanceof BranchManager) {
            return $this->forbiddenResponse('Brand owner or branch manager access required.');
        }

        $month = $request->filled('month') ? (int) $request->input('month') : null;
        $year = $request->filled('year') ? (int) $request->input('year') : null;

        // Branch managers are locked to their own branch; brand owners see all.
        $branchId = $user instanceof BranchManager ? $user->branch_id : null;

        return $this->successResponse(
            $this->service->getCustodyReportDetails($reportId, $month, $year, $branchId),
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
