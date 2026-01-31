<?php

namespace Modules\Custody\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Modules\Custody\Services\PersonalLedgerService;
use Modules\Custody\Services\PdfExportService;
use Modules\Custody\Services\CustodyBalanceService;

class LedgerController extends BaseController
{
    public function __construct(
        private PersonalLedgerService $ledgerService,
        private PdfExportService $pdfService,
        private CustodyBalanceService $balanceService
    ) {}

    /**
     * Get personal custody balance dashboard
     * GET /api/branch-manager/ledger/personal-custody-balance
     */
    public function getPersonalCustodyBalance(): JsonResponse
    {
        try {
            $balance = $this->ledgerService->getPersonalCustodyBalance(auth()->id());

            return $this->successResponse($balance, 'Personal custody balance retrieved successfully');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * Get personal balance only (for forms)
     * GET /api/branch-manager/ledger/personal-balance-only
     */
    public function getPersonalBalanceOnly(): JsonResponse
    {
        try {
            $balance = $this->ledgerService->getPersonalBalanceOnly(auth()->id());

            return $this->successResponse([
                'personalCustodyBalance' => $balance
            ], 'Personal balance retrieved successfully');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * Get transaction history
     * GET /api/branch-manager/ledger/transactions
     */
    public function getTransactions(Request $request): JsonResponse
    {
        try {
            $filters = [
                'view' => $request->input('view', 'detailed'),
                'startDate' => $request->input('startDate'),
                'endDate' => $request->input('endDate'),
                'transactionType' => $request->input('transactionType'),
            ];

            $transactions = $this->ledgerService->getTransactionHistory(auth()->id(), $filters);

            return $this->successResponse($transactions, 'Transactions retrieved successfully');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * Export transactions as PDF
     * POST /api/branch-manager/ledger/export-pdf
     */
    public function exportPdf(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        try {
            $filters = [
                'view' => $request->input('view', 'detailed'),
                'startDate' => $request->input('startDate'),
                'endDate' => $request->input('endDate'),
                'transactionType' => $request->input('transactionType'),
            ];

            $pdfContent = $this->pdfService->exportTransactionsToPdf(auth()->id(), $filters);

            // Check if dompdf was used (returns binary) or HTML (returns string)
            if (class_exists(\Barryvdh\DomPDF\Facade\Pdf::class) || class_exists('Barryvdh\DomPDF\Facade\Pdf')) {
                $filename = 'transaction_history_' . now()->format('Y-m-d_His') . '.pdf';
                return Response::make($pdfContent, 200, [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                ]);
            }

            // Fallback: return HTML
            return Response::make($pdfContent, 200, [
                'Content-Type' => 'text/html',
            ]);
        } catch (\Exception $e) {
            return Response::json([
                'success' => false,
                'message' => 'Failed to export PDF: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get branch custody balance with requests and transactions
     * GET /api/branch-manager/ledger/branch-custody-balance
     * 
     * Query Parameters:
     * - status (optional): Request status filter (All, Cash Handover, Bank Transfer, Custody Requests)
     * - timePeriod (optional): Time period filter (last_24_hours, last_7_days, last_30_days, custom)
     * - startDate (optional): Start date for custom period (YYYY-MM-DD format, required if timePeriod is custom)
     * - endDate (optional): End date for custom period (YYYY-MM-DD format, required if timePeriod is custom)
     */
    public function getBranchCustodyBalance(Request $request): JsonResponse
    {
        try {
            $filters = [
                'status' => $request->input('status'), // Request status filter (All, Cash Handover, Bank Transfer, Custody Requests)
                'timePeriod' => $request->input('timePeriod'), // Time period: last_24_hours, last_7_days, last_30_days, custom
                'startDate' => $request->input('startDate'), // Start date for custom period
                'endDate' => $request->input('endDate'), // End date for custom period
            ];

            // Validate timePeriod if provided
            $validTimePeriods = ['last_24_hours', 'last_7_days', 'last_30_days', 'custom'];
            if (!empty($filters['timePeriod']) && !in_array($filters['timePeriod'], $validTimePeriods)) {
                return $this->errorResponse(
                    'Invalid timePeriod. Must be: last_24_hours, last_7_days, last_30_days, or custom',
                    400
                );
            }

            // Validate custom period requires both dates
            if ($filters['timePeriod'] === 'custom') {
                if (empty($filters['startDate']) || empty($filters['endDate'])) {
                    return $this->errorResponse(
                        'Both startDate and endDate are required when timePeriod is custom',
                        400
                    );
                }

                // Validate date format
                try {
                    \Carbon\Carbon::parse($filters['startDate']);
                    \Carbon\Carbon::parse($filters['endDate']);
                } catch (\Exception $e) {
                    return $this->errorResponse('Invalid date format. Use YYYY-MM-DD format', 400);
                }

                // Validate startDate is before endDate
                if ($filters['startDate'] > $filters['endDate']) {
                    return $this->errorResponse('startDate must be before or equal to endDate', 400);
                }
            }

            $balance = $this->balanceService->getBranchCustodyBalance(auth()->id(), $filters);

            return $this->successResponse($balance, 'Branch custody balance retrieved successfully');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }
}
