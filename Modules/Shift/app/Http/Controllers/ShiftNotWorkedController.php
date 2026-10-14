<?php

namespace Modules\Shift\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Modules\Shift\Http\Requests\MarkShiftNotWorkedRequest;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Services\ShiftNotWorkedService;

class ShiftNotWorkedController extends BaseController
{
    public function mark(MarkShiftNotWorkedRequest $request, string $shift, ShiftNotWorkedService $service): JsonResponse
    {
        try {
            $actor = $request->user();
            $source = CashierShift::withoutEagerLoads()
                ->whereHas('shift', fn ($query) => $query->where('branch_id', $actor->branch_id))
                ->findOrFail($shift);
            $result = $service->mark($source, $actor, $request->validated('reason'));

            return $this->successResponse([
                'id' => $result->id, 'status' => $result->status->value,
                'status_label' => 'لم تعمل', 'not_worked' => $result->notWorkedOutcome(),
            ], 'Shift marked as did not work');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
            return HandoverErrorResponse::domain('SHIFT_NOT_FOUND', 404);
        } catch (\Throwable $exception) {
            return HandoverErrorResponse::from($exception, 'mark shift not worked');
        }
    }
}
