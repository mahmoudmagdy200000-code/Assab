<?php

namespace Modules\BrandOwner\Http\Controllers\Financial;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\BrandOwner\Http\Requests\Financial\ExportBreakEvenAnalysisRequest;
use Modules\BrandOwner\Services\Financial\BreakEvenAnalysisService;

class BreakEvenAnalysisController extends BaseController
{
    public function __construct(
        private readonly BreakEvenAnalysisService $service,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'year' => ['sometimes', 'nullable', 'integer'],
            'month' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:12'],
            'branch_id' => ['sometimes', 'nullable', 'string'],
        ]);

        return $this->successResponse(
            $this->service->analyze(
                isset($validated['year']) ? (int) $validated['year'] : null,
                isset($validated['month']) ? (int) $validated['month'] : null,
                $validated['branch_id'] ?? null,
            ),
            'Break-even analysis retrieved successfully',
        );
    }

    public function export(ExportBreakEvenAnalysisRequest $request): JsonResponse
    {
        $validated = $request->validated();

        return $this->successResponse(
            $this->service->export(
                $request->user(),
                (int) $validated['year'],
                (int) $validated['month'],
                $validated['branch_id'] ?? null,
                $validated['format_type'],
            ),
            'Report exported successfully',
        );
    }
}
