<?php

namespace Modules\BrandOwner\Http\Controllers\Financial;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\BrandOwner\Http\Requests\Financial\EmailPriceSimulatorScenarioRequest;
use Modules\BrandOwner\Http\Requests\Financial\ExportPriceSimulatorScenarioRequest;
use Modules\BrandOwner\Http\Requests\Financial\PriceSimulatorSubmitRequest;
use Modules\BrandOwner\Services\Financial\PriceSimulatorService;

class PriceSimulatorController extends BaseController
{
    public function __construct(
        private readonly PriceSimulatorService $service,
    ) {}

    public function items(): JsonResponse
    {
        return $this->successResponse(
            $this->service->items(),
            'Items retrieved successfully',
        );
    }

    public function itemInfo(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => ['required', 'string'],
            'item_id' => ['required', 'string'],
        ]);

        $data = $this->service->itemInfo($validated['branch_id'], $validated['item_id']);

        if ($data === null) {
            return $this->notFoundResponse('Item not found for the selected branch');
        }

        return $this->successResponse($data, 'Item info retrieved successfully');
    }

    public function simulate(PriceSimulatorSubmitRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $data = $this->service->simulate(
            auth()->user(),
            $validated['branch_id'],
            $validated['item_id'],
            (float) $validated['change_price'],
            (float) $validated['expected_growth_decline'],
        );

        if ($data === null) {
            return $this->notFoundResponse('Item not found for the selected branch');
        }

        return $this->createdResponse($data, 'Scenario simulated successfully');
    }

    public function savedScenarios(): JsonResponse
    {
        return $this->successResponse(
            $this->service->savedScenarios(auth()->user()),
            'Saved scenarios retrieved successfully',
        );
    }

    public function export(ExportPriceSimulatorScenarioRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $data = $this->service->export(
            auth()->user(),
            $validated['scenario_id'],
            $validated['format_type'],
        );

        if ($data === null) {
            return $this->notFoundResponse('Scenario not found');
        }

        return $this->successResponse($data, 'Report exported successfully');
    }

    public function email(EmailPriceSimulatorScenarioRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $sent = $this->service->email(
            auth()->user(),
            $validated['scenario_id'],
            $validated['email'],
        );

        if (! $sent) {
            return $this->notFoundResponse('Scenario not found');
        }

        return $this->successResponse(null, 'Report emailed successfully');
    }
}
