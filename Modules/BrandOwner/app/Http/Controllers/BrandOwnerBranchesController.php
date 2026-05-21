<?php

namespace Modules\BrandOwner\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Modules\BrandOwner\Services\BrandOwnerReportsService;

/**
 * Brand Owner branch list (used by report filters). No pagination.
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
        return $this->successResponse(
            $this->service->getBranches(),
            'Branches retrieved successfully'
        );
    }
}
