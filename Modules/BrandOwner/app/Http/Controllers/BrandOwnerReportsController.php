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
 * Every endpoint is guarded by the `branch.manager.or.brand.owner` middleware,
 * so the authenticated user is always a BrandOwner or a BranchManager.
 *
 * A branch manager is fully scoped to their own branch — report details,
 * exports and export history all cover that branch only. A brand owner keeps
 * the original cross-branch behaviour.
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
        $user = auth()->user();

        if (! $user instanceof BrandOwner && ! $user instanceof BranchManager) {
            return $this->forbiddenResponse('Brand owner or branch manager access required.');
        }

        return $this->successResponse(
            $this->service->getReportsAndAnalytics($user),
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
     *
     * A branch manager exports a file covering their own branch only; a brand
     * owner exports across every branch.
     */
    public function exportExpense(ExportExpenseReportRequest $request): JsonResponse
    {
        $user = auth()->user();

        if (! $user instanceof BrandOwner && ! $user instanceof BranchManager) {
            return $this->forbiddenResponse('Brand owner or branch manager access required.');
        }

        $branchId = $user instanceof BranchManager ? $user->branch_id : null;

        return $this->successResponse(
            $this->service->exportExpenseReport($user, $request->validated(), $branchId),
            'Expense report exported successfully'
        );
    }

    /**
     * POST /brand-owner/reports/custody/export
     *
     * A branch manager exports a file covering their own branch only; a brand
     * owner exports across every branch.
     */
    public function exportCustody(ExportCustodyReportRequest $request): JsonResponse
    {
        $user = auth()->user();

        if (! $user instanceof BrandOwner && ! $user instanceof BranchManager) {
            return $this->forbiddenResponse('Brand owner or branch manager access required.');
        }

        $branchId = $user instanceof BranchManager ? $user->branch_id : null;

        return $this->successResponse(
            $this->service->exportCustodyReport($user, $request->validated(), $branchId),
            'Custody report exported successfully'
        );
    }
}
