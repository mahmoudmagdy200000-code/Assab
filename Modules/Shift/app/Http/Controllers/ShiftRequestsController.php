<?php

namespace Modules\Shift\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Services\ShiftRequestsService;
use Modules\Shift\Transformers\CashierShiftResource;
use Modules\Shift\Transformers\HandoverSummaryResource;
use Modules\Shift\Transformers\VarianceSummaryResource;

/**
 * ShiftRequestsController
 *
 * Endpoints for the Requests screen: handovers, variances, and reassigned shifts.
 * Data is filtered by the authenticated user (auth token).
 * - Handovers & Variances: available to both Cashier and Branch Manager.
 * - Reassigned shifts: only for Cashiers (shifts reassigned TO the current cashier).
 */
class ShiftRequestsController extends BaseController
{
    public function __construct(
        private ShiftRequestsService $requestsService
    ) {}

    /**
     * 1 - All handovers for the authenticated user's shifts.
     * GET /api/branch-manager/requests/handovers (or shared prefix).
     * Cashier: handovers where they are the shift cashier or next cashier.
     * Branch Manager: handovers for shifts in their branch.
     *
     * @param Request $request optional ?status=pending|approved|rejected|rejected_final
     * @return JsonResponse
     */
    public function handovers(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated',
                ], 401);
            }

            $status = $request->query('status');
            $perPage = (int) $request->input('per_page', 15);
            $paginator = $this->requestsService->getHandoversForAuthUser($status, $perPage);

            $resource = HandoverSummaryResource::collection($paginator);
            $resolved = $resource->toArray($request);

            return response()->json(array_merge(
                ['success' => true, 'message' => 'Handovers retrieved successfully'],
                $resolved
            ));
        } catch (\Exception $e) {
            return $this->handleException($e, 'Failed to retrieve handovers');
        }
    }

    /**
     * 2 - All variances for the authenticated user.
     * GET /api/branch-manager/requests/variances (or shared prefix).
     * Cashier: variances for their shifts. Branch Manager: variances for their branch.
     *
     * @param Request $request optional ?status=pending|approved|rejected
     * @return JsonResponse
     */
    public function variances(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated',
                ], 401);
            }

            $status = $request->query('status');
            $perPage = (int) $request->input('per_page', 15);
            $paginator = $this->requestsService->getVariancesForAuthUser($status, $perPage);

            $resource = VarianceSummaryResource::collection($paginator);
            $resolved = $resource->toArray($request);

            return response()->json(array_merge(
                ['success' => true, 'message' => 'Variances retrieved successfully'],
                $resolved
            ));
        } catch (\Exception $e) {
            return $this->handleException($e, 'Failed to retrieve variances');
        }
    }

    /**
     * 3 - All reassigned shifts for the authenticated cashier (shifts reassigned TO them).
     * GET /api/cashier/requests/reassigned-shifts
     * Only for cashiers; returns 403 if user is not a cashier.
     *
     * @return JsonResponse
     */
    public function reassignedShifts(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated',
                ], 401);
            }

            if (!$user instanceof Cashier) {
                return response()->json([
                    'success' => false,
                    'message' => 'This endpoint is only available for cashiers',
                ], 403);
            }

            $perPage = (int) $request->input('per_page', 15);
            $paginator = $this->requestsService->getReassignedShiftsForCashier($perPage);

            $resource = CashierShiftResource::collection($paginator);
            $resolved = $resource->toArray($request);

            return response()->json(array_merge(
                ['success' => true, 'message' => 'Reassigned shifts retrieved successfully'],
                $resolved
            ));
        } catch (\Exception $e) {
            return $this->handleException($e, 'Failed to retrieve reassigned shifts');
        }
    }
}
