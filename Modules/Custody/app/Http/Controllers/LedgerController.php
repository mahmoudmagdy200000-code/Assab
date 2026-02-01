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
     *
     * Query Parameters:
     * - month (optional): Month number (1-12)
     * - year (optional): Year (e.g., 2024)
     *   Note: If both month and year are provided, filters transactions by that month/year
     */
    public function getPersonalCustodyBalance(Request $request): JsonResponse
    {
        try {
            $month = $request->input('month');
            $year = $request->input('year');

            $monthValue = null;
            $yearValue = null;

            // Validate month and year if provided
            if (!empty($month) && !empty($year)) {
                $monthValue = (int) $month;
                $yearValue = (int) $year;

                // Validate month and year
                if ($monthValue < 1 || $monthValue > 12) {
                    return $this->errorResponse('Month must be between 1 and 12', 400);
                }

                if ($yearValue < 2000 || $yearValue > 2100) {
                    return $this->errorResponse('Year must be between 2000 and 2100', 400);
                }
            }

            // Get balance with optional month/year filter
            $balance = $this->ledgerService->getPersonalCustodyBalance(
                auth()->id(),
                $monthValue,
                $yearValue
            );

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
     *
     * Query Parameters:
     * - view (optional): View type (detailed, daily) - default: detailed
     * - month (optional): Month number (1-12)
     * - year (optional): Year (e.g., 2024)
     * - transactionType (optional): Transaction type filter
     */
    public function getTransactions(Request $request): JsonResponse
    {
        try {
            $month = $request->input('month');
            $year = $request->input('year');

            // Validate month and year if provided
            if (!empty($month)) {
                $month = (int) $month;
                if ($month < 1 || $month > 12) {
                    return $this->errorResponse('Month must be between 1 and 12', 400);
                }
            }

            if (!empty($year)) {
                $year = (int) $year;
                if ($year < 2000 || $year > 2100) {
                    return $this->errorResponse('Year must be between 2000 and 2100', 400);
                }
            }

            // If one is provided, both must be provided
            if ((!empty($month) && empty($year)) || (empty($month) && !empty($year))) {
                return $this->errorResponse('Both month and year must be provided together, or both omitted', 400);
            }

            $filters = [
                'view' => $request->input('view', 'detailed'),
                'transactionType' => $request->input('transactionType'),
            ];

            // Only add month and year if both are provided
            if (!empty($month) && !empty($year)) {
                $filters['month'] = $month;
                $filters['year'] = $year;
            }

            $transactions = $this->ledgerService->getTransactionHistory(auth()->id(), $filters);

            return $this->successResponse($transactions, 'Transactions retrieved successfully');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * Export transactions as PDF
     * POST /api/branch-manager/ledger/export-pdf
     *
     * Query Parameters:
     * - view (optional): View type (detailed, daily) - default: detailed
     * - timePeriod (optional): Time period filter (last_24_hours, last_7_days, last_30_days, last_90_days, last_365_days)
     * - transactionType (optional): Transaction type filter
     */
    public function exportPdf(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        try {
            $timePeriod = $request->input('timePeriod');

            // Validate timePeriod if provided
            $validTimePeriods = ['last_24_hours', 'last_7_days', 'last_30_days', 'last_90_days', 'last_365_days'];
            if (!empty($timePeriod) && !in_array($timePeriod, $validTimePeriods)) {
                return Response::json([
                    'success' => false,
                    'message' => 'Invalid timePeriod. Must be: last_24_hours, last_7_days, last_30_days, last_90_days, or last_365_days'
                ], 400);
            }

            $filters = [
                'view' => $request->input('view', 'detailed'),
                'timePeriod' => $timePeriod,
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
