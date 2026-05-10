<?php

namespace Modules\FixedAssets\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Modules\FixedAssets\Services\OverviewService;

class OverviewController extends BaseController
{
    public function __construct(
        private readonly OverviewService $overviewService,
    ) {}

    public function index(): JsonResponse
    {
        $manager = auth()->user();
        $stats = $this->overviewService->stats($manager->branch_id);

        return response()->json($stats);
    }
}
