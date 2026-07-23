<?php

namespace Modules\BrandOwner\Http\Controllers\Financial;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\BrandOwner\Http\Requests\Financial\EmailSalesChannelAnalysisRequest;
use Modules\BrandOwner\Http\Requests\Financial\ExportSalesChannelAnalysisRequest;
use Modules\BrandOwner\Http\Requests\Financial\ExportSalesChannelLevel2Request;
use Modules\BrandOwner\Services\Financial\FinancialReportExporter;
use Modules\BrandOwner\Services\Financial\SalesChannelService;

/**
 * Sales Channel financial reports: analysis (+ export/email) and level-2 (+ export).
 * Thin controller — all computation lives in SalesChannelService; files are built
 * by FinancialReportExporter.
 */
class SalesChannelController extends BaseController
{
    public function __construct(
        private readonly SalesChannelService $service,
        private readonly FinancialReportExporter $exporter,
    ) {}

    public function analysis(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'year' => ['nullable', 'integer'],
            'month' => ['nullable', 'integer'],
            'compared_year' => ['nullable', 'integer'],
            'compared_month' => ['nullable', 'integer'],
            'branch_id' => ['nullable', 'string'],
        ]);

        return $this->successResponse(
            $this->service->analysis($validated),
            'Sales channel analysis retrieved successfully',
        );
    }

    public function analysisExport(ExportSalesChannelAnalysisRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $report = $this->service->analysisReport($validated);

        $result = $this->exporter->export(
            auth()->user(),
            'sales_channel_analysis',
            $report['title'],
            $report['sections'],
            $validated['format_type'],
            $validated,
        );

        return $this->successResponse($result, 'Report exported successfully');
    }

    public function analysisEmail(EmailSalesChannelAnalysisRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $report = $this->service->analysisReport($validated);

        $this->exporter->email(
            'sales_channel_analysis',
            $report['title'],
            $report['sections'],
            $validated['email'],
        );

        return $this->successResponse(null, 'Report emailed successfully');
    }

    public function level2(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'year' => ['nullable', 'integer'],
            'month' => ['nullable', 'integer'],
            'compared_year' => ['nullable', 'integer'],
            'compared_month' => ['nullable', 'integer'],
            'branch_id' => ['nullable', 'string'],
        ]);

        return $this->successResponse(
            $this->service->level2($validated),
            'Sales channel level 2 retrieved successfully',
        );
    }

    public function level2Export(ExportSalesChannelLevel2Request $request): JsonResponse
    {
        $validated = $request->validated();
        $report = $this->service->level2Report($validated);

        $result = $this->exporter->export(
            auth()->user(),
            'sales_channel_level2',
            $report['title'],
            $report['sections'],
            $validated['format_type'],
            $validated,
        );

        return $this->successResponse($result, 'Report exported successfully');
    }
}
