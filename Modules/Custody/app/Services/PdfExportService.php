<?php

namespace Modules\Custody\Services;

use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Storage;
use Modules\Custody\Services\PersonalLedgerService;

class PdfExportService
{
    public function __construct(
        private PersonalLedgerService $ledgerService
    ) {}

    /**
     * Export transactions as PDF and save to storage
     * Returns the file path and URL
     */
    public function exportTransactionsToPdf(string $branchManagerId, array $filters = []): array
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

        // Generate filename
        $filename = 'transaction_history_' . $branchManagerId . '_' . now()->format('Y-m-d_His') . '.pdf';
        $filePath = 'custody/reports/' . $filename;

        // Check if dompdf is available
        if (class_exists(\Barryvdh\DomPDF\Facade\Pdf::class) || class_exists('Barryvdh\DomPDF\Facade\Pdf')) {
            $pdfContent = $this->exportWithDompdf($data);
            
            // Save PDF to storage
            Storage::disk('public')->put($filePath, $pdfContent);
            
            return [
                'file_path' => $filePath,
                'file_url' => asset('storage/' . $filePath),
                'filename' => $filename,
            ];
        }

        // Fallback: save HTML
        $htmlContent = $this->exportAsHtml($data);
        $htmlFilename = str_replace('.pdf', '.html', $filename);
        $htmlFilePath = 'custody/reports/' . $htmlFilename;
        
        Storage::disk('public')->put($htmlFilePath, $htmlContent);
        
        return [
            'file_path' => $htmlFilePath,
            'file_url' => asset('storage/' . $htmlFilePath),
            'filename' => $htmlFilename,
        ];
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
