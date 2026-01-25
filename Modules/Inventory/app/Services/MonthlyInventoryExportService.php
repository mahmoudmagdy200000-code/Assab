<?php

namespace Modules\Inventory\Services;

use Illuminate\Support\Facades\View;
use Modules\Inventory\Models\MonthlyInventory;

class MonthlyInventoryExportService
{
    public function __construct(
        private readonly MonthlyInventoryService $monthlyService
    ) {}

    /**
     * Generate PDF report and return raw content.
     */
    public function generatePdf(string $inventoryId): string
    {
        $report = $this->monthlyService->getReport($inventoryId);
        $inventory = $report['inventory'];
        $summary = $report['summary'];
        $products = $report['products'];

        $productsData = $products->map(fn ($p) => [
            'item_name' => $p->item_name,
            'quantity' => (float) $p->quantity_inventory,
            'unit' => $p->unit ?? '-',
            'unit_price' => (float) $p->unit_price,
            'line_value' => number_format($p->line_value, 2),
        ])->all();

        $total = $summary['products_total'] ?? 0;
        $complete = $summary['products_complete'] ?? 0;
        $pct = $total > 0 ? round(($complete / $total) * 100, 1) : 0;

        $data = [
            'branchName' => $inventory->branch?->name ?? 'Branch',
            'period' => $inventory->inventory_date?->format('F Y') ?? '-',
            'status' => $inventory->status?->label() ?? '-',
            'productsComplete' => $complete,
            'productsTotal' => $total,
            'productsPct' => $pct,
            'timeTaken' => $summary['time_taken_formatted'] ?? '-',
            'participantsCount' => $summary['participants_count'] ?? 0,
            'totalValueFormatted' => number_format($summary['total_value'] ?? 0, 2),
            'products' => $productsData,
            'generatedAt' => now()->format('Y-m-d H:i:s'),
        ];

        $html = View::make('inventory::pdf.monthly-report', $data)->render();

        if (class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)) {
            $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadHTML($html);
            $pdf->setPaper('A4', 'portrait');
            return $pdf->output();
        }

        if (app()->bound('dompdf.wrapper')) {
            $pdf = app('dompdf.wrapper')->loadHTML($html);
            $pdf->setPaper('A4', 'portrait');
            return $pdf->output();
        }

        throw new \RuntimeException('PDF generator (DomPDF) not available.');
    }

    /**
     * Generate CSV (Excel-compatible) and return raw content.
     */
    public function generateCsv(string $inventoryId): string
    {
        $report = $this->monthlyService->getReport($inventoryId);
        $inventory = $report['inventory'];
        $summary = $report['summary'];
        $products = $report['products'];

        $lines = [];
        $lines[] = ['Complete Monthly Inventory Report'];
        $lines[] = [$inventory->inventory_date?->format('F Y') ?? '', $inventory->branch?->name ?? ''];
        $lines[] = [];
        $lines[] = ['Status', $inventory->status?->label() ?? ''];
        $lines[] = ['Products Complete', ($summary['products_complete'] ?? 0) . ' / ' . ($summary['products_total'] ?? 0)];
        $lines[] = ['Time Taken', $summary['time_taken_formatted'] ?? ''];
        $lines[] = ['Participants', $summary['participants_count'] ?? 0];
        $lines[] = ['Total Value', $summary['total_value'] ?? 0];
        $lines[] = [];
        $lines[] = ['Item', 'Quantity', 'Unit', 'Unit Price', 'Line Value'];

        foreach ($products as $p) {
            $lines[] = [
                $p->item_name,
                (float) $p->quantity_inventory,
                $p->unit ?? '-',
                (float) $p->unit_price,
                round($p->line_value, 2),
            ];
        }

        $out = fopen('php://temp', 'r+');
        foreach ($lines as $row) {
            fputcsv($out, $row);
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return $csv;
    }
}
