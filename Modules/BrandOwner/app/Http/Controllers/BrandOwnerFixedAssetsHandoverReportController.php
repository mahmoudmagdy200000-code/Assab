<?php

namespace Modules\BrandOwner\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Modules\BrandOwner\Models\BrandOwner;
use Modules\BrandOwner\Services\BrandOwnerFixedAssetsHandoverReportService;
use Modules\BrandOwner\Transformers\BrandOwnerFixedAssetsHandoverReportDetailsResource;
use Modules\BrandOwner\Transformers\BrandOwnerFixedAssetsHandoverReportListResource;

class BrandOwnerFixedAssetsHandoverReportController extends BaseController
{
    public function __construct(
        private readonly BrandOwnerFixedAssetsHandoverReportService $service,
    ) {}

    public function index(): JsonResponse
    {
        if (! $this->isBrandOwner()) {
            return $this->forbiddenResponse('Brand owner role required.');
        }

        return $this->successResponse(
            BrandOwnerFixedAssetsHandoverReportListResource::collection($this->service->list())->resolve(),
            'Handover reports retrieved successfully',
        );
    }

    public function show(string $id): JsonResponse
    {
        if (! $this->isBrandOwner()) {
            return $this->forbiddenResponse('Brand owner role required.');
        }

        try {
            $req = $this->service->find($id);
        } catch (ModelNotFoundException) {
            return $this->notFoundResponse('Handover report not found');
        }

        return $this->successResponse(
            (new BrandOwnerFixedAssetsHandoverReportDetailsResource($req))->toArray(request()),
            'Handover report details retrieved successfully',
        );
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        if (! $this->isBrandOwner()) {
            return $this->forbiddenResponse('Brand owner role required.');
        }

        $validator = Validator::make($request->all(), [
            'warningNote' => 'required|string|min:1|max:1000',
        ]);
        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        try {
            $req = $this->service->approve($id, auth()->user(), (string) $request->input('warningNote'));
        } catch (ModelNotFoundException) {
            return $this->notFoundResponse('Handover report not found');
        } catch (\Throwable $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }

        return $this->successResponse(
            [
                'data' => (new BrandOwnerFixedAssetsHandoverReportDetailsResource($req))->toArray(request()),
                'side_effects' => [[
                    'kind' => 'major_discrepancy_record_update',
                    'employee_name' => (string) ($req->employee_responsible ?? ''),
                    'reason' => (string) ($req->warning_note ?? ''),
                ]],
            ],
            'Handover report approved',
        );
    }

    public function salaryDeduction(Request $request, string $id): JsonResponse
    {
        if (! $this->isBrandOwner()) {
            return $this->forbiddenResponse('Brand owner role required.');
        }

        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:0',
            'reason' => 'required|string|min:3|max:500',
        ]);
        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        try {
            $req = $this->service->salaryDeduction(
                $id,
                auth()->user(),
                (string) $request->input('amount'),
                (string) $request->input('reason'),
            );
        } catch (ModelNotFoundException) {
            return $this->notFoundResponse('Handover report not found');
        } catch (\Throwable $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }

        return $this->successResponse(
            [
                'data' => (new BrandOwnerFixedAssetsHandoverReportDetailsResource($req))->toArray(request()),
                'side_effects' => [
                    [
                        'kind' => 'major_discrepancy_record_update',
                        'employee_name' => (string) ($req->employee_responsible ?? ''),
                        'amount' => (float) $req->salary_deduction_amount,
                        'reason' => (string) ($req->salary_deduction_reason ?? ''),
                        'note' => (string) ($req->salary_deduction_note ?? ''),
                    ],
                    [
                        'kind' => 'handover_item_marked_deducted',
                        'handover_item_id' => (string) $req->handover_item_id,
                        'isDeducted' => true,
                    ],
                ],
            ],
            'Salary deduction applied',
        );
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        if (! $this->isBrandOwner()) {
            return $this->forbiddenResponse('Brand owner role required.');
        }

        $validator = Validator::make($request->all(), [
            'reason' => 'required|string|min:3|max:500',
        ]);
        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        try {
            $req = $this->service->reject($id, auth()->user(), (string) $request->input('reason'));
        } catch (ModelNotFoundException) {
            return $this->notFoundResponse('Handover report not found');
        } catch (\Throwable $e) {
            return $this->errorResponse($e->getMessage(), 400);
        }

        return $this->successResponse(
            [
                'data' => (new BrandOwnerFixedAssetsHandoverReportDetailsResource($req))->toArray(request()),
                'side_effects' => [],
            ],
            'Handover report rejected',
        );
    }

    private function isBrandOwner(): bool
    {
        return auth()->user() instanceof BrandOwner;
    }
}
