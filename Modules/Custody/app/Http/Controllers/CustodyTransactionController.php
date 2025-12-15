<?php

namespace Modules\Custody\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Custody\Services\CustodyTransactionService;

class CustodyTransactionController extends BaseController
{
    public function __construct(
        private CustodyTransactionService $transactionService
    ) {}

    /**
     * List custody transactions
     * GET /api/custody/transactions
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $filters = [
                'type' => $request->input('type'),
                'startDate' => $request->input('startDate'),
                'endDate' => $request->input('endDate'),
            ];

            $transactions = $this->transactionService->listTransactions(auth()->id(), $filters);

            return $this->successResponse($transactions, 'Transactions retrieved successfully');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }
}
