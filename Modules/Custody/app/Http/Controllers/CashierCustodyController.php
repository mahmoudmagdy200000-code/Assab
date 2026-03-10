<?php

namespace Modules\Custody\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Custody\Services\CashierCustodyService;

class CashierCustodyController extends BaseController
{
    public function __construct(
        private CashierCustodyService $custodyService
    ) {}

    /**
     * GET /api/cashier/custody/balance
     * Personal custody balance summary (Total Cash In / Out, Recent Activities).
     *
     * Query Parameters:
     * - month (optional, 1-12)
     * - year  (optional, e.g. 2026)
     */
    public function balance(Request $request): JsonResponse
    {
        try {
            $month = $request->input('month') ? (int) $request->input('month') : null;
            $year  = $request->input('year')  ? (int) $request->input('year')  : null;

            if (($month && !$year) || (!$month && $year)) {
                return $this->errorResponse('Both month and year must be provided together, or both omitted', 400);
            }

            if ($month && ($month < 1 || $month > 12)) {
                return $this->errorResponse('Month must be between 1 and 12', 400);
            }

            $balance = $this->custodyService->getPersonalCustodyBalance(auth()->id(), $month, $year);

            return $this->successResponse($balance, 'Custody balance retrieved successfully');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * GET /api/cashier/custody/transactions
     * Paginated custody transaction history.
     *
     * Query Parameters:
     * - month            (optional, 1-12)
     * - year             (optional)
     * - transaction_type (optional): "Handover Received" | "Handover Sent"
     */
    public function transactions(Request $request): JsonResponse
    {
        try {
            $month = $request->input('month') ? (int) $request->input('month') : null;
            $year  = $request->input('year')  ? (int) $request->input('year')  : null;

            if (($month && !$year) || (!$month && $year)) {
                return $this->errorResponse('Both month and year must be provided together, or both omitted', 400);
            }

            $validTypes = ['Handover Received', 'Handover Sent', 'Total Sales'];
            $type = $request->input('transaction_type');
            if ($type && !in_array($type, $validTypes)) {
                return $this->errorResponse('Invalid transaction_type. Must be: ' . implode(', ', $validTypes), 400);
            }

            $filters = array_filter([
                'month'            => $month,
                'year'             => $year,
                'transactionType'  => $type,
            ]);

            $result = $this->custodyService->getTransactionHistory(auth()->id(), $filters);

            return $this->successResponse($result, 'Transactions retrieved successfully');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }
}
