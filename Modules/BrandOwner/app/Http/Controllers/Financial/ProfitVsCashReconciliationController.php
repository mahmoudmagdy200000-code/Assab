<?php

namespace Modules\BrandOwner\Http\Controllers\Financial;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Modules\BrandOwner\Http\Requests\Financial\EmailProfitVsCashReconciliationRequest;
use Modules\BrandOwner\Http\Requests\Financial\ExportProfitVsCashReconciliationRequest;
use Modules\BrandOwner\Services\Financial\ProfitVsCashReconciliationService;

class ProfitVsCashReconciliationController extends BaseController
{
    public function __construct(
        private readonly ProfitVsCashReconciliationService $service,
    ) {}

    public function index(): JsonResponse
    {
        return $this->successResponse(
            $this->service->reconciliation(),
            'Profit vs cash reconciliation retrieved successfully',
        );
    }

    public function export(ExportProfitVsCashReconciliationRequest $request): JsonResponse
    {
        $result = $this->service->export(
            $request->user(),
            $request->validated()['format_type'],
        );

        return $this->successResponse($result, 'Report exported successfully');
    }

    public function email(EmailProfitVsCashReconciliationRequest $request): JsonResponse
    {
        $this->service->email($request->validated()['email']);

        return $this->successResponse(null, 'Report emailed successfully');
    }
}
