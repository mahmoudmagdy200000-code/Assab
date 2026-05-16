<?php

namespace Modules\FixedAssets\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Modules\FixedAssets\Services\AssetHistoryReportService;
use Modules\FixedAssets\Services\AssetHistoryService;

class AssetHistoryController extends BaseController
{
    public function __construct(
        private readonly AssetHistoryService $history,
        private readonly AssetHistoryReportService $reportService,
    ) {}

    public function show(string $assetId): JsonResponse
    {
        /** @var \Modules\BranchManagers\Models\BranchManager $manager */
        $manager = auth()->user();

        $asset = $this->history->findForBranch($assetId, $manager->branch_id);
        if (! $asset) {
            return $this->notFoundResponse('Asset not found');
        }

        return $this->successResponse(
            ['data' => $this->history->buildPayload($asset)],
            'Asset history retrieved successfully',
        );
    }

    public function generateReport(string $assetId): JsonResponse
    {
        /** @var \Modules\BranchManagers\Models\BranchManager $manager */
        $manager = auth()->user();

        $asset = $this->history->findForBranch($assetId, $manager->branch_id);
        if (! $asset) {
            return $this->notFoundResponse('Asset not found');
        }

        $report = $this->reportService->generate($asset, $manager);

        return $this->createdResponse(
            ['data' => $this->reportService->toPayload($report)],
            'Asset history report generated successfully',
        );
    }
}
