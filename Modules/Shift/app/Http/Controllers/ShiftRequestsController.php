<?php

namespace Modules\Shift\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Services\ShiftRequestsService;
use Modules\Shift\Transformers\CashierShiftResource;

/**
 * ShiftRequestsController
 *
 * Endpoints for the Requests screen: handovers, variances, and reassigned shifts.
 * Data is filtered by the authenticated user (auth token).
 * - Handovers & Variances: available to both Cashier and Branch Manager.
 * - Reassigned shifts: only for Cashiers (shifts reassigned TO the current cashier).
 */
class ShiftRequestsController extends Controller
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
            $handovers = $this->requestsService->getHandoversForAuthUser($status);

            return response()->json([
                'success' => true,
                'message' => 'Handovers retrieved successfully',
                'data' => CashierShiftResource::collection($handovers),
                'meta' => [
                    'total' => $handovers->count(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve handovers',
                'error' => $e->getMessage(),
            ], 500);
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
            $variances = $this->requestsService->getVariancesForAuthUser($status);

            return response()->json([
                'success' => true,
                'message' => 'Variances retrieved successfully',
                'data' => CashierShiftResource::collection($variances),
                'meta' => [
                    'total' => $variances->count(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve variances',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 3 - All reassigned shifts for the authenticated cashier (shifts reassigned TO them).
     * GET /api/cashier/requests/reassigned-shifts
     * Only for cashiers; returns 403 if user is not a cashier.
     *
     * @return JsonResponse
     */
    public function reassignedShifts(): JsonResponse
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

            $shifts = $this->requestsService->getReassignedShiftsForCashier();

            return response()->json([
                'success' => true,
                'message' => 'Reassigned shifts retrieved successfully',
                'data' => CashierShiftResource::collection($shifts),
                'meta' => [
                    'total' => $shifts->count(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve reassigned shifts',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
