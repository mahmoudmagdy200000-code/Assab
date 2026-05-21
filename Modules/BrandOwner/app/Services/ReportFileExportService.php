<?php

namespace Modules\BrandOwner\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;

/**
 * Renders Brand Owner reports to a file on the public disk and returns the
 * stored relative path. PDF is produced with DomPDF; "Excel" is emitted as a
 * spreadsheet-friendly CSV (no spreadsheet package is installed).
 */
class ReportFileExportService
{
    private const DIR = 'brand-owner/reports';

    public function exportExpense(array $details, string $formatType, string $title): string
    {
        return $this->isExcel($formatType)
            ? $this->writeCsv($this->expenseCsvRows($details, $title), $title, 'expense')
            : $this->writePdf('brandowner::reports.expense', ['details' => $details, 'title' => $title], $title, 'expense');
    }

    public function exportCustody(array $details, string $formatType, string $title): string
    {
        return $this->isExcel($formatType)
            ? $this->writeCsv($this->custodyCsvRows($details, $title), $title, 'custody')
            : $this->writePdf('brandowner::reports.custody', ['details' => $details, 'title' => $title], $title, 'custody');
    }

    private function isExcel(string $formatType): bool
    {
        return strtolower($formatType) === 'excel';
    }

    private function writePdf(string $view, array $data, string $title, string $kind): string
    {
        $filename = $this->filename($kind, 'pdf');
        $path = self::DIR.'/'.$filename;
        $html = View::make($view, $data)->render();

        try {
            $pdf = $this->renderPdf($html);
            if ($pdf !== null && substr($pdf, 0, 4) === '%PDF') {
                Storage::disk('public')->put($path, $pdf);

                return $path;
            }
        } catch (\Throwable $e) {
            Log::warning('Brand owner report PDF generation failed, saving HTML fallback: '.$e->getMessage());
        }

        // Fallback: store the rendered HTML so a file URL is always returned.
        $htmlPath = self::DIR.'/'.str_replace('.pdf', '.html', $filename);
        Storage::disk('public')->put($htmlPath, $html);

        return $htmlPath;
    }

    private function renderPdf(string $html): ?string
    {
        if (class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)) {
            return \Barryvdh\DomPDF\Facade\Pdf::loadHTML($html)->setPaper('A4', 'portrait')->output();
        }

        if (app()->bound('dompdf.wrapper')) {
            return app('dompdf.wrapper')->loadHTML($html)->setPaper('A4', 'portrait')->output();
        }

        if (class_exists(\Dompdf\Dompdf::class)) {
            $dompdf = new \Dompdf\Dompdf;
            $dompdf->loadHtml($html);
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();

            return $dompdf->output();
        }

        return null;
    }

    private function writeCsv(array $rows, string $title, string $kind): string
    {
        $filename = $this->filename($kind, 'csv');
        $path = self::DIR.'/'.$filename;

        $handle = fopen('php://temp', 'r+');
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        Storage::disk('public')->put($path, $csv);

        return $path;
    }

    private function expenseCsvRows(array $details, string $title): array
    {
        $summary = $details['summary'] ?? [];
        $rows = [
            [$title],
            ['Period', $summary['period_label'] ?? ''],
            ['Branch', $summary['branch']['name'] ?? '-'],
            ['Total Requests', $summary['total_requests'] ?? 0],
            ['Total Amount', $summary['total_amount'] ?? 0],
            ['Trend', $summary['trend_percent'] ?? ''],
            [],
            ['Payment Method', 'Count', 'Amount'],
        ];

        foreach ($details['payment_methods'] ?? [] as $pm) {
            $rows[] = [$pm['method'], $pm['count'], $pm['amount']];
        }

        $rows[] = [];
        $rows[] = ['Top Supplier', 'Amount'];
        foreach ($details['top_suppliers'] ?? [] as $s) {
            $rows[] = [$s['name'], $s['amount']];
        }

        $rows[] = [];
        $rows[] = ['Expense Ratio', 'Count', 'Amount'];
        foreach ($details['expense_ratios'] ?? [] as $r) {
            $rows[] = [$r['type'], $r['count'], $r['amount']];
        }

        $rows[] = [];
        $rows[] = ['Branch', 'Amount'];
        foreach ($details['branch_comparisons'] ?? [] as $b) {
            $rows[] = [$b['name'], $b['amount']];
        }

        return $rows;
    }

    private function custodyCsvRows(array $details, string $title): array
    {
        $rows = [
            [$title],
            ['Period', $details['period_label'] ?? ''],
            [],
            ['Branch', 'Location', 'Opening Balance', 'Cash In', 'Cash Out', 'Current Balance'],
        ];

        foreach ($details['branches'] ?? [] as $branch) {
            $amounts = $branch['amounts'] ?? [];
            $rows[] = [
                $branch['name'] ?? '',
                $branch['branch'] ?? '',
                $amounts['month_opening_balance'] ?? 0,
                $amounts['total_cash_in'] ?? 0,
                $amounts['total_cash_out'] ?? 0,
                $branch['current_balance'] ?? 0,
            ];
        }

        return $rows;
    }

    private function filename(string $kind, string $extension): string
    {
        return $kind.'_report_'.now()->format('Ymd_His').'_'.Str::random(6).'.'.$extension;
    }
}
