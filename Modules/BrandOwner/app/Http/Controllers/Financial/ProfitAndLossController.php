<?php

namespace Modules\BrandOwner\Http\Controllers\Financial;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\BrandOwner\Http\Requests\Financial\EmailProfitAndLossRequest;
use Modules\BrandOwner\Http\Requests\Financial\ExportProfitAndLossRequest;
use Modules\BrandOwner\Services\Financial\ProfitAndLossService;

class ProfitAndLossController extends BaseController
{
    public function __construct(
        private readonly ProfitAndLossService $service,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $data = $this->service->build(
            $request->filled('year') ? (int) $request->query('year') : null,
            $request->filled('month') ? (int) $request->query('month') : null,
            $request->query('branch_id'),
        );

        return $this->successResponse($data, 'Profit and loss statement retrieved successfully');
    }

    public function export(ExportProfitAndLossRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $result = $this->service->export(
            auth()->user(),
            (int) $validated['year'],
            (int) $validated['month'],
            $validated['branch_id'] ?? null,
            $validated['format_type'],
        );

        return $this->successResponse($result, 'Report exported successfully');
    }

    public function email(EmailProfitAndLossRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $this->service->email(
            (int) $validated['year'],
            (int) $validated['month'],
            $validated['branch_id'] ?? null,
            $validated['email'],
        );

        return $this->successResponse(null, 'Report emailed successfully');
    }
}
