<?php

namespace Modules\BranchManagers\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\BranchManagers\Transformers\PriceComparisonDetailsResource;
use Modules\Purchase\Models\SavedPriceComparison;

/**
 * Renders a saved price comparison to a downloadable file on the public disk
 * and returns the stored relative path.
 *
 * PDF is produced with DomPDF (with an HTML fallback so a file URL is always
 * returned); "Excel" is emitted as a spreadsheet-friendly CSV because no
 * spreadsheet package is installed in this project.
 */
class PriceComparisonExportService
{
    private const DIR = 'branch-manager/price-comparisons';

    /**
     * Export the comparison and return the public-disk relative path.
     */
    public function export(SavedPriceComparison $comparison, string $formatType): string
    {
        // Reuse the API transformer so the file mirrors the details endpoint.
        $data = (new PriceComparisonDetailsResource($comparison))->toArray(request());

        return $this->isExcel($formatType)
            ? $this->writeCsv($this->csvRows($data), $comparison->id)
            : $this->writePdf($this->html($data), $comparison->id);
    }

    private function isExcel(string $formatType): bool
    {
        return strtolower($formatType) === 'excel';
    }

    private function writePdf(string $html, string $id): string
    {
        $filename = $this->filename($id, 'pdf');
        $path = self::DIR.'/'.$filename;

        try {
            $pdf = $this->renderPdf($html);
            if ($pdf !== null && substr($pdf, 0, 4) === '%PDF') {
                Storage::disk('public')->put($path, $pdf);

                return $path;
            }
        } catch (\Throwable $e) {
            Log::warning('Price comparison PDF generation failed, saving HTML fallback: '.$e->getMessage());
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

    private function writeCsv(array $rows, string $id): string
    {
        $filename = $this->filename($id, 'csv');
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

    /**
     * @param  array<string, mixed>  $d  Transformed comparison details.
     * @return array<int, array<int, mixed>>
     */
    private function csvRows(array $d): array
    {
        $rows = [
            ['Price Comparison', $d['itemName'] ?? ''],
            ['Item Code', $d['itemCode'] ?? ''],
            ['Unit', $d['itemUnit'] ?? ''],
            ['Quantity', $d['quantity'] ?? ''],
            ['Item Price', $d['itemPrice'] ?? ''],
            [],
            ['Source', 'Price', 'Delivery', 'Rating', 'Source ID'],
        ];

        foreach ($this->sourceLabels() as $key => $label) {
            $source = $d['sources'][$key] ?? null;
            if (is_array($source)) {
                $rows[] = [
                    $label,
                    $source['price'] ?? '',
                    $source['deliveryDays'] ?? '',
                    $source['rating'] ?? '',
                    $source['sourceId'] ?? '',
                ];
            }
        }

        $rows[] = [];
        $rows[] = ['Factor', 'Source Type', 'Source Name', 'Score'];
        foreach ($this->factorLabels() as $key => $label) {
            $factor = $d['factors'][$key] ?? null;
            if (is_array($factor)) {
                $rows[] = [
                    $label,
                    $factor['sourceType'] ?? '',
                    $factor['sourceName'] ?? '',
                    $factor['score'] ?? '',
                ];
            }
        }

        $rows[] = [];
        $rows[] = ['Price Trend Date', 'Value'];
        foreach ($d['priceTrends'] ?? [] as $trend) {
            $rows[] = [$trend['date'] ?? '', $trend['value'] ?? ''];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $d  Transformed comparison details.
     */
    private function html(array $d): string
    {
        $e = static fn ($value) => htmlspecialchars((string) ($value ?? ''), ENT_QUOTES);

        $sourceRows = '';
        foreach ($this->sourceLabels() as $key => $label) {
            $source = $d['sources'][$key] ?? null;
            if (! is_array($source)) {
                continue;
            }
            $sourceRows .= '<tr><td>'.$e($label).'</td><td>'.$e($source['price'] ?? null)
                .'</td><td>'.$e($source['deliveryDays'] ?? null).'</td><td>'.$e($source['rating'] ?? null).'</td></tr>';
        }

        $factorRows = '';
        foreach ($this->factorLabels() as $key => $label) {
            $factor = $d['factors'][$key] ?? null;
            if (! is_array($factor)) {
                continue;
            }
            $factorRows .= '<tr><td>'.$e($label).'</td><td>'.$e($factor['sourceName'] ?? null)
                .'</td><td>'.$e($factor['score'] ?? null).'</td></tr>';
        }

        $trendRows = '';
        foreach ($d['priceTrends'] ?? [] as $trend) {
            $trendRows .= '<tr><td>'.$e($trend['date'] ?? null).'</td><td>'.$e($trend['value'] ?? null).'</td></tr>';
        }

        return '<!doctype html><html><head><meta charset="utf-8"><style>'
            .'body{font-family:DejaVu Sans,Arial,sans-serif;font-size:12px;color:#222}'
            .'h1{font-size:18px;margin-bottom:4px}h2{font-size:14px;margin-top:18px}'
            .'table{width:100%;border-collapse:collapse;margin-top:6px}'
            .'th,td{border:1px solid #ccc;padding:6px;text-align:left}'
            .'th{background:#f3f4f6}</style></head><body>'
            .'<h1>Price Comparison &mdash; '.$e($d['itemName'] ?? null).'</h1>'
            .'<p>Code: '.$e($d['itemCode'] ?? null).' &nbsp; Unit: '.$e($d['itemUnit'] ?? null)
            .' &nbsp; Quantity: '.$e($d['quantity'] ?? null).' &nbsp; Item Price: '.$e($d['itemPrice'] ?? null).'</p>'
            .'<h2>Sources</h2><table><thead><tr><th>Source</th><th>Price</th><th>Delivery</th><th>Rating</th></tr></thead>'
            .'<tbody>'.($sourceRows ?: '<tr><td colspan="4">No sources available</td></tr>').'</tbody></table>'
            .'<h2>Factors</h2><table><thead><tr><th>Factor</th><th>Source</th><th>Score</th></tr></thead>'
            .'<tbody>'.($factorRows ?: '<tr><td colspan="3">No factors available</td></tr>').'</tbody></table>'
            .'<h2>Price Trends</h2><table><thead><tr><th>Date</th><th>Value</th></tr></thead>'
            .'<tbody>'.($trendRows ?: '<tr><td colspan="2">No data</td></tr>').'</tbody></table>'
            .'</body></html>';
    }

    /**
     * @return array<string, string>
     */
    private function sourceLabels(): array
    {
        return [
            'directSupplier' => 'Direct Supplier',
            'viaPurchasingOfficer' => 'Via Purchasing Officer',
            'internalTransfer' => 'Internal Transfer',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function factorLabels(): array
    {
        return [
            'bestCompliance' => 'Best Compliance',
            'fastestDelivery' => 'Fastest Delivery',
            'lowestPrice' => 'Lowest Price',
        ];
    }

    private function filename(string $id, string $extension): string
    {
        return 'price_comparison_'.$id.'_'.now()->format('Ymd_His').'_'.Str::random(6).'.'.$extension;
    }
}
