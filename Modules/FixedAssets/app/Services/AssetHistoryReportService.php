<?php

namespace Modules\FixedAssets\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\BranchManagers\Models\BranchManager;
use Modules\FixedAssets\Models\AssetHistoryReport;
use Modules\FixedAssets\Models\FixedAsset;

class AssetHistoryReportService
{
    public function __construct(
        private readonly AssetHistoryService $history,
    ) {}

    public function generate(FixedAsset $asset, BranchManager $manager): AssetHistoryReport
    {
        $payload = $this->history->buildPayload($asset);
        $generatedAt = now();

        $pdf = Pdf::loadView('fixedassets::reports.asset-history', [
            'asset' => $asset,
            'payload' => $payload,
            'generatedAt' => $generatedAt,
        ]);

        $slug = Str::slug($asset->code ?: $asset->id);
        $fileName = "asset-history-{$slug}-".$generatedAt->format('YmdHis').'.pdf';
        $path = 'fixed-assets/reports/'.$fileName;

        Storage::disk('public')->put($path, $pdf->output());

        return AssetHistoryReport::create([
            'asset_id' => $asset->id,
            'branch_id' => $asset->branch_id,
            'generated_by_id' => $manager->id,
            'file_name' => $fileName,
            'path' => $path,
            'generated_at' => $generatedAt,
        ]);
    }

    public function toPayload(AssetHistoryReport $report): array
    {
        return [
            'reportId' => (string) $report->id,
            'fileName' => (string) $report->file_name,
            'downloadUrl' => asset('storage/'.$report->path),
            'generatedAt' => optional($report->generated_at)->toIso8601String() ?? '',
        ];
    }
}
