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

        // Try to generate PDF using DomPDF
        try {
            $pdfContent = $this->exportWithDompdf($data);
            
            // Verify it's actually PDF content (starts with %PDF)
            if (substr($pdfContent, 0, 4) === '%PDF') {
                // Save PDF to storage
                Storage::disk('public')->put($filePath, $pdfContent);
                
                return [
                    'file_path' => $filePath,
                    'file_url' => asset('storage/' . $filePath),
                    'filename' => $filename,
                ];
            }
        } catch (\Exception $e) {
            // If PDF generation fails, fall back to HTML
            \Log::warning('PDF generation failed, falling back to HTML: ' . $e->getMessage());
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

        // Try multiple methods to get DomPDF instance
        $pdf = null;

        // Method 1: Try facade
        if (class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)) {
            try {
                $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadHTML($html);
            } catch (\Exception $e) {
                // Continue to next method
            }
        }

        // Method 2: Try service container
        if (!$pdf && app()->bound('dompdf.wrapper')) {
            try {
                $pdf = app('dompdf.wrapper')->loadHTML($html);
            } catch (\Exception $e) {
                // Continue to next method
            }
        }

        // Method 3: Try direct instantiation
        if (!$pdf && class_exists('Dompdf\Dompdf')) {
            try {
                $dompdf = new \Dompdf\Dompdf();
                $dompdf->loadHtml($html);
                $dompdf->setPaper('A4', 'portrait');
                $dompdf->render();
                return $dompdf->output();
            } catch (\Exception $e) {
                // Continue to throw error
            }
        }

        if (!$pdf) {
            throw new \Exception('DomPDF is not available. Please ensure barryvdh/laravel-dompdf is installed and configured.');
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
