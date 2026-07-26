<?php

namespace Modules\BrandOwner\Services;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Branch\Models\Branch;
use Modules\FixedAssets\Enums\AssetStatus;
use Modules\FixedAssets\Models\FixedAsset;
use Modules\FixedAssets\Models\TransferDisposalItem;

class BrandOwnerAssetOverviewService
{
    private const EXPORT_DIR = 'brand-owner/asset-overview';

    public function overview(): array
    {
        $branches = $this->branches();

        return [
            'branches' => $branches,
            'network_statistics' => $this->networkStatistics(),
            'performance_summary' => $this->performanceSummary(),
        ];
    }

    public function performanceSummary(): array
    {
        $total = FixedAsset::query()->count();
        $excellent = FixedAsset::query()
            ->where('status', AssetStatus::EXCELLENT->value)
            ->count();

        $excellentPercent = $total > 0 ? (int) round(($excellent / $total) * 100) : 0;

        return [
            'assets_in_excellent_condition' => $excellent,
            'excellent_percent' => $excellentPercent.'%',
            'photo_compliance' => $this->photoCompliance().'%',
            'average_resolution_time' => '00:00 Mins',
            'last_audit_score' => 0,
        ];
    }

    public function branchDetails(string $branchId): array
    {
        $branch = Branch::query()->find($branchId);

        if (! $branch) {
            throw new ModelNotFoundException("Branch not found: {$branchId}");
        }

        return $this->branchObject($branch);
    }

    public function exportReport(string $branchId, string $format): array
    {
        $branch = Branch::query()->find($branchId);

        if (! $branch) {
            throw new ModelNotFoundException("Branch not found: {$branchId}");
        }

        $details = $this->branchObject($branch);
        $path = $this->writeExportFile($details, $format);

        return [
            'branch_id' => (string) $branch->id,
            'branch_name' => (string) ($branch->name ?? ''),
            'format' => $format,
            'url' => Storage::disk('public')->url($path),
            'message' => "Report exported successfully in {$format} format.",
        ];
    }

    private function writeExportFile(array $details, string $format): string
    {
        $isExcel = strtolower($format) === 'excel';
        $extension = $isExcel ? 'csv' : 'pdf';
        $filename = 'asset_overview_'.now()->format('Ymd_His').'_'.Str::random(6).'.'.$extension;
        $path = self::EXPORT_DIR.'/'.$filename;

        if ($isExcel) {
            Storage::disk('public')->put($path, $this->exportCsv($details));

            return $path;
        }

        $html = $this->exportHtml($details);
        $pdf = $this->renderPdf($html);

        if ($pdf !== null && substr($pdf, 0, 4) === '%PDF') {
            Storage::disk('public')->put($path, $pdf);

            return $path;
        }

        $htmlPath = self::EXPORT_DIR.'/'.str_replace('.pdf', '.html', $filename);
        Storage::disk('public')->put($htmlPath, $html);

        return $htmlPath;
    }

    private function exportCsv(array $details): string
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ['Branch', $details['branch_name']]);
        fputcsv($handle, ['Manager', $details['manager_name']]);
        fputcsv($handle, ['Assets', $details['assets_count']]);
        fputcsv($handle, ['Excellent', $details['excellent_count']]);
        fputcsv($handle, ['Need Attention', $details['attention_count']]);
        fputcsv($handle, ['Problem', $details['problem_count']]);
        fputcsv($handle, []);
        fputcsv($handle, ['Name', 'Code', 'Zone', 'Custodian', 'Custody Duration', 'Last Audit', 'Transfers']);

        foreach ($details['assets'] ?? [] as $asset) {
            fputcsv($handle, [
                $asset['name'],
                $asset['code'],
                $asset['zone_name'],
                $asset['custodian_name'],
                $asset['custody_duration'],
                $asset['last_audit_date'],
                $asset['transfers_count'],
            ]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    private function exportHtml(array $details): string
    {
        $rows = '';
        foreach ($details['assets'] ?? [] as $a) {
            $rows .= '<tr>'
                .'<td>'.e($a['name']).'</td>'
                .'<td>'.e($a['code']).'</td>'
                .'<td>'.e($a['zone_name']).'</td>'
                .'<td>'.e($a['custodian_name']).'</td>'
                .'<td>'.e($a['custody_duration']).'</td>'
                .'<td>'.e($a['last_audit_date']).'</td>'
                .'<td>'.(int) $a['transfers_count'].'</td>'
                .'</tr>';
        }

        return '<!doctype html><html><head><meta charset="utf-8"><title>Asset Overview</title>'
            .'<style>body{font-family:Arial,sans-serif;}table{border-collapse:collapse;width:100%;}th,td{border:1px solid #ccc;padding:6px;text-align:left;}</style>'
            .'</head><body>'
            .'<h1>'.e($details['branch_name']).'</h1>'
            .'<p>Manager: '.e($details['manager_name']).'</p>'
            .'<p>Assets: '.(int) $details['assets_count']
            .' | Excellent: '.(int) $details['excellent_count']
            .' | Attention: '.(int) $details['attention_count']
            .' | Problem: '.(int) $details['problem_count'].'</p>'
            .'<table><thead><tr><th>Name</th><th>Code</th><th>Zone</th><th>Custodian</th><th>Custody</th><th>Last Audit</th><th>Transfers</th></tr></thead>'
            .'<tbody>'.$rows.'</tbody></table>'
            .'</body></html>';
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

    private function branches(): array
    {
        return Branch::query()
            ->with(['branchManager:id,name,image,branch_id'])
            ->orderBy('name')
            ->get()
            ->map(fn (Branch $b) => $this->branchObject($b))
            ->all();
    }

    private function branchObject(Branch $branch): array
    {
        $assets = FixedAsset::query()
            ->with(['zone:id,name', 'assignedTo'])
            ->where('branch_id', $branch->id)
            ->orderBy('name')
            ->get();

        $statusCounts = $assets->groupBy(fn (FixedAsset $a) => $a->status?->value)->map->count();
        $manager = $branch->branchManager ?? $branch->managers()->first();

        return [
            'id' => (string) $branch->id,
            'branch_name' => (string) ($branch->name ?? ''),
            'manager_name' => (string) ($manager?->name ?? ''),
            'manager_image_url' => $manager?->image ? asset('storage/'.$manager->image) : null,
            'assets_count' => $assets->count(),
            'excellent_count' => (int) ($statusCounts[AssetStatus::EXCELLENT->value] ?? 0),
            'attention_count' => (int) ($statusCounts[AssetStatus::NEED_ATTENTION->value] ?? 0),
            'problem_count' => (int) ($statusCounts[AssetStatus::PROBLEM->value] ?? 0),
            'assets' => $assets->map(fn (FixedAsset $a) => $this->assetObject($a, $manager?->name))->all(),
        ];
    }

    private function assetObject(FixedAsset $asset, ?string $defaultCustodian): array
    {
        $transfers = TransferDisposalItem::query()
            ->where('asset_id', $asset->id)
            ->count();

        $custodianName = $defaultCustodian ?? '';
        if ($asset->relationLoaded('assignedTo') && $asset->assignedTo && isset($asset->assignedTo->name)) {
            $custodianName = (string) $asset->assignedTo->name;
        }

        $custody = $asset->custody_started_at
            ? $asset->custody_started_at->diffForHumans(now(), [
                'syntax' => \Carbon\CarbonInterface::DIFF_ABSOLUTE,
                'parts' => 1,
            ])
            : '';

        return [
            'id' => (string) $asset->id,
            'name' => (string) ($asset->name ?? ''),
            'code' => (string) ($asset->code ?? ''),
            'zone_name' => (string) ($asset->zone?->name ?? ''),
            'custodian_name' => $custodianName,
            'custody_duration' => $custody,
            'last_audit_date' => $asset->last_updated_at?->format('M d, Y') ?? '',
            'photo_date' => $asset->updated_at?->isToday() ? 'Today' : ($asset->updated_at?->format('M d, Y') ?? ''),
            'transfers_count' => $transfers,
            'image_url' => $asset->image ? asset('storage/'.$asset->image) : '',
        ];
    }

    private function networkStatistics(): array
    {
        $total = FixedAsset::query()->count();
        $excellent = FixedAsset::query()
            ->where('status', AssetStatus::EXCELLENT->value)
            ->count();

        $excellentPercent = $total > 0 ? (int) round(($excellent / $total) * 100) : 0;

        $avgAgeYears = (float) FixedAsset::query()
            ->whereNotNull('acquired_at')
            ->get()
            ->avg(fn (FixedAsset $a) => $a->acquired_at->floatDiffInYears(now()));

        return [
            'total_assets' => $total,
            'excellent_status' => $excellentPercent.'%',
            'photo_accuracy' => $this->photoCompliance().'%',
            'average_asset_age' => $avgAgeYears > 0
                ? number_format($avgAgeYears, 1).' Years'
                : '0 Years',
        ];
    }

    private function photoCompliance(): int
    {
        $total = FixedAsset::query()->count();
        if ($total === 0) {
            return 0;
        }

        $withImage = FixedAsset::query()
            ->whereNotNull('image')
            ->where('image', '!=', '')
            ->count();

        return (int) round(($withImage / $total) * 100);
    }
}
