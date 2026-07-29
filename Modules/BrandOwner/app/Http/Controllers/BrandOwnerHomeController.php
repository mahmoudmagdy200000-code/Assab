<?php

namespace Modules\BrandOwner\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Modules\Admin\Services\MobileBranchScopeService;
use Modules\BrandOwner\Http\Requests\BrandOwnerHomeDashboardRequest;
use Modules\BrandOwner\Services\BrandOwnerHomeService;

/**
 * Brand Owner Home dashboard (BrandOwnerHomeScreen).
 *
 * Routes are guarded by the `brand.owner` middleware, so the authenticated
 * user is guaranteed to be a BrandOwner here.
 */
class BrandOwnerHomeController extends BaseController
{
    public function __construct(
        private BrandOwnerHomeService $service,
        private MobileBranchScopeService $branchScope
    ) {}

    /**
     * GET /brand-owner/dashboard/branches
     */
    public function branches(): JsonResponse
    {
        return $this->successResponse(
            $this->service->getBranches($this->branchScope->visibleBranchIds(auth()->user())),
            'Branches retrieved successfully'
        );
    }

    /**
     * GET /brand-owner/dashboard
     */
    public function dashboard(BrandOwnerHomeDashboardRequest $request): JsonResponse
    {
        return $this->successResponse(
            $this->service->getDashboard(
                $request->filters(),
                $this->branchScope->visibleBranchIds(auth()->user()),
            ),
            'Dashboard retrieved successfully'
        );
    }
}
