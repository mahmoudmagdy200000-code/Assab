<?php

namespace Modules\Custody\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;
use Modules\Custody\Services\PersonalLedgerService;
use Modules\Custody\Services\PdfExportService;

class LedgerController extends BaseController
{
    public function __construct(
        private PersonalLedgerService $ledgerService,
        private PdfExportService $pdfService
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
}
