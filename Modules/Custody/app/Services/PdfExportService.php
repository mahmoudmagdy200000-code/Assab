<?php

namespace Modules\Custody\Services;

use Illuminate\Support\Facades\View;
use Modules\Custody\Services\PersonalLedgerService;

class PdfExportService
{
    public function __construct(
        private PersonalLedgerService $ledgerService
    ) {}

    /**
     * Export transactions as PDF
     */
    public function exportTransactionsToPdf(string $branchManagerId, array $filters = []): string
    {
        $transactionsData = $this->ledgerService->getTransactionHistory($branchManagerId, $filters);
        $branchManager = \Modules\BranchManagers\Models\BranchManager::find($branchManagerId);

        // Prepare data for PDF
        $data = [
            'branchManager' => $branchManager,
            'transactions' => $transactionsData['transactions'],
            'view' => $transactionsData['view'],
            'totalTransactions' => $transactionsData['totalTransactions'],
            'filters' => $filters,
            'generatedAt' => now()->format('Y-m-d H:i:s'),
        ];

        // Check if dompdf is available
        if (class_exists(\Barryvdh\DomPDF\Facade\Pdf::class) || class_exists('Barryvdh\DomPDF\Facade\Pdf')) {
            return $this->exportWithDompdf($data);
        }

        // Fallback: return HTML that can be printed
        return $this->exportAsHtml($data);
    }

    /**
     * Export using DomPDF
     */
    private function exportWithDompdf(array $data): string
    {
        $html = View::make('custody::pdf.transactions', $data)->render();

        // Use the facade if available, otherwise use the service directly
        if (class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)) {
            $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadHTML($html);
        } else {
            $pdf = app('dompdf.wrapper')->loadHTML($html);
        }

        $pdf->setPaper('A4', 'portrait');

        return $pdf->output();
    }

    /**
     * Export as HTML (fallback)
     */
    private function exportAsHtml(array $data): string
    {
        return View::make('custody::pdf.transactions', $data)->render();
    }
}
