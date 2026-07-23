<?php

namespace Modules\BrandOwner\Http\Controllers\Financial;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Modules\BrandOwner\Http\Requests\Financial\EmailItemTestRequest;
use Modules\BrandOwner\Http\Requests\Financial\ExportItemTestRequest;
use Modules\BrandOwner\Http\Requests\Financial\ItemTestSubmitRequest;
use Modules\BrandOwner\Services\Financial\ItemTestService;

class ItemTestController extends BaseController
{
    public function __construct(
        private readonly ItemTestService $service,
    ) {}

    public function submit(ItemTestSubmitRequest $request): JsonResponse
    {
        $result = $this->service->submit(auth()->user(), $request->validated());

        return $this->createdResponse($result, 'Item test submitted successfully');
    }

    public function savedTests(): JsonResponse
    {
        return $this->successResponse(
            $this->service->savedTests(auth()->user()),
            'Saved tests retrieved successfully',
        );
    }

    public function export(ExportItemTestRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $result = $this->service->export(
            auth()->user(),
            $validated['test_id'],
            $validated['format_type'],
        );

        if ($result === null) {
            return $this->notFoundResponse('Item test not found');
        }

        return $this->successResponse($result, 'Report exported successfully');
    }

    public function email(EmailItemTestRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $sent = $this->service->email(
            auth()->user(),
            $validated['test_id'],
            $validated['email'],
        );

        if (! $sent) {
            return $this->notFoundResponse('Item test not found');
        }

        return $this->successResponse(null, 'Report emailed successfully');
    }
}
