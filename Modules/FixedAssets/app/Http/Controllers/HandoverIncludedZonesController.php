<?php

namespace Modules\FixedAssets\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\FixedAssets\Services\HandoverIncludedZonesService;

class HandoverIncludedZonesController extends BaseController
{
    public function __construct(
        private readonly HandoverIncludedZonesService $service,
    ) {}

    public function index(Request $request): JsonResponse
    {
        /** @var \Modules\BranchManagers\Models\BranchManager $manager */
        $manager = auth()->user();

        $payload = $this->service->forBranch(
            $manager->branch_id,
            (string) $request->query('status_filter', 'global'),
        );

        return $this->successResponse($payload, 'Included zones retrieved successfully');
    }
}
