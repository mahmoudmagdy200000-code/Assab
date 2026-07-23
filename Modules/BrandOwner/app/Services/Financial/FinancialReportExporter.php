<?php

namespace Modules\BrandOwner\Services\Financial;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Modules\BrandOwner\Jobs\SendFinancialReportJob;
use Modules\BrandOwner\Models\BrandOwnerReportExport;

/**
 * Renders any Brand Owner financial report to a file on the public disk and
 * returns its public URL, and queues the same report as an email attachment.
 *
 * Reports hand over a normalised, presentation-agnostic payload (title + a list
 * of sections, each either key/value rows or a table). PDF is produced with
 * DomPDF when available; "Excel" is emitted as spreadsheet-friendly CSV (no
 * spreadsheet package is installed — same convention as ReportFileExportService).
 *
 * A section is one of:
 *   ['heading' => string, 'rows'  => [['label' => string, 'value' => scalar], ...]]
 *   ['heading' => string, 'table' => ['headers' => string[], 'rows' => scalar[][]]]
 */
class FinancialReportExporter
{
    private const DIR = 'brand-owner/financial-reports';

    /**
     * Build the file and persist an export record, returning {file_url}.
     *
     * @param  array<int, array<string, mixed>>  $sections
     * @param  array<string, mixed>  $params  echo of the request params, stored for history
     * @return array{file_url: string}
     */
    public function export(
        Model $owner,
        string $reportKind,
        string $title,
        array $sections,
        string $formatType,
        array $params = []
    ): array {
        $filePath = $this->write($title, $sections, $formatType, $reportKind);

        $export = BrandOwnerReportExport::create([
            'brand_owner_id' => $owner->getKey(),
            'report_kind' => $reportKind,
            'format' => $this->normalizeFormat($formatType),
            'title' => $title,
            'file_path' => $filePath,
            'params' => $params,
        ]);

        return ['file_url' => $export->download_url];
    }

    /**
     * Queue an email delivery of the report to $email with the file attached.
     *
     * @param  array<int, array<string, mixed>>  $sections
     */
    public function email(
        string $reportKind,
        string $title,
        array $sections,
        string $email,
        string $formatType = 'PDF'
    ): void {
        $filePath = $this->write($title, $sections, $formatType, $reportKind);
        $absolute = Storage::disk('public')->path($filePath);

        SendFinancialReportJob::dispatch(
            $email,
            $title,
            $absolute,
            basename($filePath),
            $this->mimeFor($filePath),
        );
    }

    // ----------------------------------------------------------------
    // File writing
    // ----------------------------------------------------------------

    /**
     * @param  array<int, array<string, mixed>>  $sections
     */
    private function write(string $title, array $sections, string $formatType, string $kind): string
    {
        return $this->isExcel($formatType)
            ? $this->writeCsv($title, $sections, $kind)
            : $this->writePdf($title, $sections, $kind);
    }

    private function isExcel(string $formatType): bool
    {
        return strtolower($formatType) === 'excel';
    }

    /**
     * @param  array<int, array<string, mixed>>  $sections
     */
    private function writePdf(string $title, array $sections, string $kind): string
    {
        $filename = $this->filename($kind, 'pdf');
        $path = self::DIR.'/'.$filename;
        $html = View::make('brandowner::reports.financial', [
            'title' => $title,
            'sections' => $sections,
        ])->render();

        try {
            $pdf = $this->renderPdf($html);
            if ($pdf !== null && substr($pdf, 0, 4) === '%PDF') {
                Storage::disk('public')->put($path, $pdf);

                return $path;
            }
        } catch (\Throwable $e) {
            Log::warning('Financial report PDF generation failed, saving HTML fallback: '.$e->getMessage());
        }

        // Fallback: always return a downloadable file even without a PDF engine.
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

    /**
     * @param  array<int, array<string, mixed>>  $sections
     */
    private function writeCsv(string $title, array $sections, string $kind): string
    {
        $filename = $this->filename($kind, 'csv');
        $path = self::DIR.'/'.$filename;

        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, [$title]);
        fputcsv($handle, []);

        foreach ($sections as $section) {
            fputcsv($handle, [$section['heading'] ?? '']);

            if (! empty($section['rows'])) {
                foreach ($section['rows'] as $row) {
                    fputcsv($handle, [$row['label'] ?? '', $row['value'] ?? '']);
                }
            }

            if (! empty($section['table'])) {
                fputcsv($handle, $section['table']['headers'] ?? []);
                foreach ($section['table']['rows'] ?? [] as $tableRow) {
                    fputcsv($handle, $tableRow);
                }
            }

            fputcsv($handle, []);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        Storage::disk('public')->put($path, $csv);

        return $path;
    }

    private function filename(string $kind, string $extension): string
    {
        return $kind.'_'.now()->format('Ymd_His').'_'.Str::random(6).'.'.$extension;
    }

    private function mimeFor(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'pdf' => 'application/pdf',
            'csv' => 'text/csv',
            default => 'text/html',
        };
    }

    private function normalizeFormat(string $formatType): string
    {
        return strtolower($formatType) === 'excel' ? 'excel' : 'pdf';
    }
}
