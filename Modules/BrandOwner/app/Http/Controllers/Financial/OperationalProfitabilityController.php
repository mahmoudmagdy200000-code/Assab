<?php

namespace Modules\BrandOwner\Http\Controllers\Financial;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Modules\BrandOwner\Http\Requests\Financial\EmailOperationalProfitabilityRequest;
use Modules\BrandOwner\Http\Requests\Financial\ExportOperationalProfitabilityRequest;
use Modules\BrandOwner\Services\Financial\OperationalProfitabilityService;

class OperationalProfitabilityController extends BaseController
{
    public function __construct(
        private readonly OperationalProfitabilityService $service,
    ) {}

    public function index(): JsonResponse
    {
        return $this->successResponse(
            $this->service->report(),
            'Operational profitability report retrieved successfully',
        );
    }

    public function export(ExportOperationalProfitabilityRequest $request): JsonResponse
    {
        $result = $this->service->export(
            auth()->user(),
            $request->validated()['format_type'],
        );

        return $this->successResponse($result, 'Report exported successfully');
    }

    public function email(EmailOperationalProfitabilityRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $this->service->email(
            $validated['email'],
            // Blank / omitted format_type defaults to PDF.
            ($validated['format_type'] ?? null) ?: 'PDF',
        );

        return $this->successResponse(null, 'Report emailed successfully');
    }
}
