<?php

namespace Modules\BrandOwner\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Modules\BranchManagers\Models\BranchManager;
use Modules\BrandOwner\Models\BrandOwner;
use Modules\BrandOwner\Services\BrandOwnerReportsService;

/**
 * Brand Owner branch list (used by report filters). No pagination.
 *
 * Reachable by brand owners and branch managers. A brand owner receives every
 * branch; a branch manager receives only their own branch.
 */
class BrandOwnerBranchesController extends BaseController
{
    public function __construct(
        private BrandOwnerReportsService $service
    ) {}

    /**
     * GET /brand-owner/branches
     */
    public function index(): JsonResponse
    {
        $user = auth()->user();

        if (! $user instanceof BrandOwner && ! $user instanceof BranchManager) {
            return $this->forbiddenResponse('Brand owner or branch manager access required.');
        }

        $branchId = $user instanceof BranchManager ? $user->branch_id : null;

        return $this->successResponse(
            $this->service->getBranches($branchId),
            'Branches retrieved successfully'
        );
    }
}
