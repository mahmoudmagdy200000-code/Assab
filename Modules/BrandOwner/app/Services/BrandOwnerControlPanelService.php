<?php

namespace Modules\BrandOwner\Services;

use Modules\Branch\Models\Branch;
use Modules\FixedAssets\Enums\AssetStatus;
use Modules\FixedAssets\Models\FixedAsset;

class BrandOwnerControlPanelService
{
    public function controlPanel(): array
    {
        return [
            'strategic_overview' => $this->strategicOverview(),
            'branch_performance' => $this->branchPerformance(),
            'executive_summary' => $this->executiveSummary(),
        ];
    }

    private function strategicOverview(): array
    {
        $total = FixedAsset::query()->count();
        $excellent = FixedAsset::query()
            ->where('status', AssetStatus::EXCELLENT->value)
            ->count();
        $excellentPercent = $total > 0 ? (int) round(($excellent / $total) * 100) : 0;

        $branchesCount = Branch::query()->count();
        $totalValue = (float) FixedAsset::query()->sum('value');
        $problemCount = FixedAsset::query()
            ->where('status', AssetStatus::PROBLEM->value)
            ->count();

        return [
            'total_chain_assets' => $total,
            'excellent_status' => $excellentPercent.'%',
            'branches_count' => $branchesCount,
            'total_investment' => $this->formatInvestment($totalValue),
            'critical_value_impact' => $problemCount > 0 ? 'Warning' : 'Stable',
        ];
    }

    private function branchPerformance(): array
    {
        $items = Branch::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(function (Branch $b) {
                $total = FixedAsset::query()->where('branch_id', $b->id)->count();
                $excellent = FixedAsset::query()
                    ->where('branch_id', $b->id)
                    ->where('status', AssetStatus::EXCELLENT->value)
                    ->count();
                $percent = $total > 0 ? (int) round(($excellent / $total) * 100) : 0;

                return [
                    'name' => (string) ($b->name ?? ''),
                    'percentage' => $percent.'%',
                    'status' => $this->statusLabel($percent),
                ];
            })
            ->all();

        return ['items' => $items];
    }

    private function executiveSummary(): array
    {
        $total = FixedAsset::query()->count();
        $withImage = FixedAsset::query()
            ->whereNotNull('image')
            ->where('image', '!=', '')
            ->count();
        $photoAccuracy = $total > 0 ? (int) round(($withImage / $total) * 100) : 0;

        $branchesCount = Branch::query()->count();
        $branchesWithAssets = FixedAsset::query()
            ->distinct('branch_id')
            ->count('branch_id');

        return [
            'photo_accuracy' => $photoAccuracy.'%',
            'audit_completion' => "{$branchesWithAssets} of {$branchesCount} Branches",
        ];
    }

    private function statusLabel(int $percent): string
    {
        return match (true) {
            $percent >= 97 => 'Ideal',
            $percent >= 92 => 'Excellent',
            $percent >= 88 => 'Good',
            default => 'Needs Improvement',
        };
    }

    private function formatInvestment(float $value): string
    {
        if ($value >= 1_000_000) {
            return number_format($value / 1_000_000, 1).'M';
        }

        if ($value >= 1_000) {
            return number_format($value / 1_000, 1).'K';
        }

        return (string) (int) $value;
    }
}
