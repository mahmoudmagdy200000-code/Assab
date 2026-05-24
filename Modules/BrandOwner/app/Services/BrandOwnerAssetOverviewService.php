<?php

namespace Modules\BrandOwner\Services;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Modules\Branch\Models\Branch;
use Modules\FixedAssets\Enums\AssetStatus;
use Modules\FixedAssets\Models\FixedAsset;
use Modules\FixedAssets\Models\TransferDisposalItem;

class BrandOwnerAssetOverviewService
{
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

        return [
            'branch_id' => (string) $branch->id,
            'branch_name' => (string) ($branch->name ?? ''),
            'format' => $format,
            'message' => "Report exported successfully in {$format} format.",
        ];
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
