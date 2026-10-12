<?php

namespace Modules\BrandOwner\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\Services\MobileBranchScopeService;
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
 * so the authenticated user is always a BrandOwner / BrandManager (brand actor)
 * or a BranchManager (branch-level).
 *
 * TENANT ISOLATION (A-1): every report, detail and export is confined to the
 * branches the authenticated actor is actually assigned to, resolved
 * server-side and applied INSIDE the DB query before any aggregation, grouping
 * or export — never from a client-supplied id.
 *   - brand owner / brand manager → the branches of the brands they own
 *     (MobileBranchScopeService); an unlinked actor fails closed (empty scope,
 *     no data), and a BrandManager inherits NOTHING extra from BrandOwner.
 *   - branch-level manager        → their own branch only (unchanged).
 * A client-supplied branch_id can only NARROW the authorised scope.
 */
class BrandOwnerReportsController extends BaseController
{
    public function __construct(
        private BrandOwnerReportsService $service,
        private MobileBranchScopeService $branchScope
    ) {}

    /**
     * Branch ids the authenticated actor may report on (empty = fail closed).
     *
     * A branch-level manager stays locked to exactly their own branch (no
     * regression, no brand widening); a brand owner / brand manager gets the
     * branches of the brands they are assigned to.
     *
     * @return string[]
     */
    private function allowedBranchIds(mixed $user): array
    {
        if ($user instanceof BranchManager) {
            return $user->branch_id ? [$user->branch_id] : [];
        }

        return $this->branchScope->visibleBranchIds($user);
    }

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
        $filters['allowed_branch_ids'] = $this->allowedBranchIds($user);

        // A branch-level manager is pinned to their own branch — the client's
        // branch_id is ignored and the summary/filters echo that branch.
        if ($user instanceof BranchManager) {
            $filters['branch_id'] = $user->branch_id;
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

        // Custody has no client branch filter; the actor's authorised branches
        // (own branch for a branch-level manager, own brand for a brand actor)
        // are the only ones returned.
        return $this->successResponse(
            $this->service->getCustodyReportDetails($reportId, $month, $year, $this->allowedBranchIds($user)),
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

        return $this->successResponse(
            $this->service->exportExpenseReport($user, $request->validated(), $this->allowedBranchIds($user)),
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

        return $this->successResponse(
            $this->service->exportCustodyReport($user, $request->validated(), $this->allowedBranchIds($user)),
            'Custody report exported successfully'
        );
    }
}
