<?php

namespace Modules\Admin\Services;

use Illuminate\Support\Collection;
use Modules\Admin\Models\Operation;

/**
 * SRS ACC-4.2/4.4 — the inventory review surface: the monthly compare table
 * (previous vs current month per item, % change chip) with **server-side**
 * anomaly detection, and the KPI block.
 *
 * Before T07 `index` returned each branch's latest payload verbatim and trusted
 * a client-supplied `isAnomaly` flag; the `type` toggle was dead. Anomaly is now
 * computed from the figures (>50% swing), never from the uploading device.
 */
class InventoryReviewService
{
    /** BR-08 chip thresholds and the anomaly swing, in percent. */
    private const CHIP_DOWN = -30.0;

    private const CHIP_UP = 30.0;

    private const ANOMALY_PCT = 50.0;

    /**
     * @param  string[]|null  $branchIds  assigned-branch scope (null = company-wide)
     * @return array{branches: array<int, array<string,mixed>>, summary: array<string,mixed>}
     */
    public function overview(string $companyId, ?array $branchIds, string $type = 'monthly'): array
    {
        $ops = Operation::where('company_id', $companyId)
            ->where('module_key', 'inventory')
            ->when($branchIds !== null, fn ($q) => $q->whereIn('branch_id', $branchIds))
            ->orderByDesc('operation_date')
            ->limit(5000)->get();

        $curMonth = now()->format('Y-m');
        $prevMonth = now()->subMonthNoOverflow()->format('Y-m');

        $byBranch = $ops->groupBy('branch_id');
        $branches = [];
        $anomalyAlerts = 0;
        $lowItems = 0;
        $normalItems = 0;

        foreach ($byBranch as $branchId => $group) {
            [$currentOp, $items] = $this->branchItems($group, $type, $curMonth, $prevMonth);
            if ($currentOp === null) {
                continue;
            }
            $branchAnomalies = count(array_filter($items, fn ($i) => $i['isAnomaly']));
            $anomalyAlerts += $branchAnomalies;
            $lowItems += count(array_filter($items, fn ($i) => $i['isLow']));
            $normalItems += count(array_filter($items, fn ($i) => ! $i['isLow'] && ! $i['isAnomaly']));

            $branches[] = [
                'branchId' => $branchId,
                'operationId' => $currentOp->id,
                'status' => $currentOp->status,
                'items' => $items,
                'anomalyCount' => $branchAnomalies,
                'isFlagged' => (bool) ($currentOp->payload['isFlagged'] ?? false),
                'branchConfirmed' => (bool) ($currentOp->payload['branchReconfirmedAt'] ?? ($currentOp->payload['isConfirmed'] ?? false)),
                'flaggedItemIndices' => $currentOp->payload['flaggedItemIndices'] ?? [],
            ];
        }

        return [
            'branches' => $branches,
            'summary' => $this->summary($companyId, $branchIds, $ops, $branches, $lowItems, $normalItems, $anomalyAlerts),
        ];
    }

    /**
     * The current op and its compared item rows for one branch.
     *
     * @return array{0: ?Operation, 1: array<int, array<string,mixed>>}
     */
    private function branchItems(Collection $group, string $type, string $curMonth, string $prevMonth): array
    {
        $current = $group->first();
        if ($current === null) {
            return [null, []];
        }

        // Monthly compare needs the previous month's submission for the same branch.
        $prevItems = [];
        if ($type === 'monthly') {
            $prevOp = $group->first(fn ($o) => optional($o->operation_date)->format('Y-m') === $prevMonth);
            $prevItems = $this->indexByItem($prevOp?->payload['items'] ?? []);
        }

        $rows = [];
        foreach (($current->payload['items'] ?? []) as $idx => $it) {
            $itemId = (string) ($it['itemId'] ?? $it['catalogItemId'] ?? $it['id'] ?? ('row-'.$idx));
            $curQty = $this->qty($it);
            $prev = $prevItems[$itemId] ?? null;
            $prevQty = $prev !== null ? $this->qty($prev) : null;

            $changePct = $prevQty !== null && $prevQty != 0.0
                ? round(($curQty - $prevQty) / $prevQty * 100, 2)
                : null;
            $isAnomaly = $changePct !== null && abs($changePct) > self::ANOMALY_PCT;
            $minLevel = isset($it['minLevel']) ? (float) $it['minLevel'] : (isset($it['minQty']) ? (float) $it['minQty'] : null);

            $rows[] = [
                'itemId' => $itemId,
                'itemName' => $it['name'] ?? ($it['itemName'] ?? '—'),
                'unit' => $it['unit'] ?? null,
                'prevQty' => $prevQty,
                'currQty' => $curQty,
                'changePct' => $changePct,
                'chip' => $this->chip($changePct),
                'isAnomaly' => $isAnomaly,
                'isLow' => $minLevel !== null && $curQty <= $minLevel,
            ];
        }

        return [$current, $rows];
    }

    /** ACC-4.2 KPI block. */
    private function summary(string $companyId, ?array $branchIds, Collection $ops, array $branches, int $lowItems, int $normalItems, int $anomalyAlerts): array
    {
        $today = now()->toDateString();
        $wasteToday = (int) Operation::where('company_id', $companyId)
            ->where('module_key', 'waste')
            ->when($branchIds !== null, fn ($q) => $q->whereIn('branch_id', $branchIds))
            ->whereDate('operation_date', $today)->sum('amount');
        $salesToday = (int) Operation::where('company_id', $companyId)
            ->where('module_key', 'sales')
            ->when($branchIds !== null, fn ($q) => $q->whereIn('branch_id', $branchIds))
            ->whereDate('operation_date', $today)->sum('amount');

        return [
            'totalSubmissions' => $ops->count(),
            'uploadedCount' => count($branches),
            'pendingCount' => $ops->where('status', 'pending')->count(),
            'completedBranches' => collect($branches)->where('status', 'final-approved')->count(),
            'anomalyAlerts' => $anomalyAlerts,
            'lowItems' => $lowItems,
            'normalItems' => $normalItems,
            'totalWasteTodayHalalas' => $wasteToday,
            'wasteRatePct' => $salesToday > 0 ? round($wasteToday / $salesToday * 100, 2) : 0.0,
        ];
    }

    /** @return array<string, array<string,mixed>> itemId → row */
    private function indexByItem(array $items): array
    {
        $out = [];
        foreach ($items as $idx => $it) {
            $out[(string) ($it['itemId'] ?? $it['catalogItemId'] ?? $it['id'] ?? ('row-'.$idx))] = $it;
        }

        return $out;
    }

    private function chip(?float $changePct): ?string
    {
        if ($changePct === null) {
            return null;
        }

        return match (true) {
            $changePct < self::CHIP_DOWN => 'red',
            $changePct > self::CHIP_UP => 'green',
            default => 'neutral',
        };
    }

    private function qty(array $it): float
    {
        return (float) ($it['actualQty'] ?? $it['actual'] ?? $it['countedQty'] ?? $it['qty'] ?? 0);
    }
}
