<?php

namespace Modules\Admin\Services;

use Illuminate\Support\Facades\DB;
use Modules\Inventory\Models\InventoryMonthlyCost;

/**
 * INV-02: Monthly weighted average cost calculation per (company, branch, item, month).
 * Weighted Avg Cost = (Opening Value + Month Purchases Value) / (Opening Qty + Month Purchases Qty).
 * Preserves opening cost when there are no purchases in the month.
 * Internal transfers enter at the sender's fixed cost without internal markup.
 */
class MonthlyWeightedCostService
{
    public function recalculate(
        string $companyId,
        string $branchId,
        string $itemId,
        string $yearMonth,
        float $openingQty,
        int $openingValueHalalas,
        float $purchasesQty = 0.0,
        int $purchasesValueHalalas = 0,
        float $transfersInQty = 0.0,
        int $transfersInValueHalalas = 0,
        string $status = InventoryMonthlyCost::STATUS_PROVISIONAL,
        ?string $approvedById = null,
    ): InventoryMonthlyCost {
        return DB::transaction(function () use (
            $companyId,
            $branchId,
            $itemId,
            $yearMonth,
            $openingQty,
            $openingValueHalalas,
            $purchasesQty,
            $purchasesValueHalalas,
            $transfersInQty,
            $transfersInValueHalalas,
            $status,
            $approvedById
        ) {
            $totalQty = round($openingQty + $purchasesQty + $transfersInQty, 3);
            $totalValue = $openingValueHalalas + $purchasesValueHalalas + $transfersInValueHalalas;

            if ($purchasesQty <= 0.0 && $transfersInQty <= 0.0 && $openingQty > 0.0) {
                $unitCostHalalas = (int) round($openingValueHalalas / $openingQty);
            } elseif ($totalQty > 0.0) {
                $unitCostHalalas = (int) round($totalValue / $totalQty);
            } else {
                $unitCostHalalas = 0;
            }

            $existing = InventoryMonthlyCost::where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->where('item_id', $itemId)
                ->where('year_month', $yearMonth)
                ->lockForUpdate()
                ->first();

            if ($existing && $existing->status === InventoryMonthlyCost::STATUS_APPROVED) {
                $sameValues = round((float) $existing->opening_qty, 3) === round($openingQty, 3)
                    && (int) $existing->opening_value_halalas === $openingValueHalalas
                    && round((float) $existing->purchases_qty, 3) === round($purchasesQty, 3)
                    && (int) $existing->purchases_value_halalas === $purchasesValueHalalas
                    && round((float) $existing->transfers_in_qty, 3) === round($transfersInQty, 3)
                    && (int) $existing->transfers_in_value_halalas === $transfersInValueHalalas;

                if ($sameValues && $status === InventoryMonthlyCost::STATUS_APPROVED && ! empty($approvedById)) {
                    return $existing;
                }

                throw new \DomainException('Cannot overwrite an approved closed-period monthly weighted cost.');
            }

            $revision = $existing ? ((int) $existing->revision + 1) : 1;
            $attributes = [
                'opening_qty' => $openingQty,
                'opening_value_halalas' => $openingValueHalalas,
                'purchases_qty' => $purchasesQty,
                'purchases_value_halalas' => $purchasesValueHalalas,
                'transfers_in_qty' => $transfersInQty,
                'transfers_in_value_halalas' => $transfersInValueHalalas,
                'unit_cost_halalas' => $unitCostHalalas,
                'status' => $status,
                'revision' => $revision,
                'approved_by_id' => $status === InventoryMonthlyCost::STATUS_APPROVED ? $approvedById : null,
                'approved_at' => $status === InventoryMonthlyCost::STATUS_APPROVED ? now() : null,
            ];

            return $this->persistLockedMonthlyCost(
                $existing,
                [
                    'company_id' => $companyId,
                    'branch_id' => $branchId,
                    'item_id' => $itemId,
                    'year_month' => $yearMonth,
                ],
                $attributes
            );
        });
    }

    /**
     * Incrementally apply purchase quantity and/or value-only cost adjustments while
     * preserving already-approved closed-month records (DEC-07 / DEC-OPEN-02).
     *
     * @return array{applied: bool, status: string, monthly_cost: ?InventoryMonthlyCost}
     */
    public function recordPurchaseCostDelta(
        string $companyId,
        string $branchId,
        string $itemId,
        string $yearMonth,
        float $qtyDelta = 0.0,
        int $valueDeltaHalalas = 0,
    ): array {
        return DB::transaction(function () use (
            $companyId,
            $branchId,
            $itemId,
            $yearMonth,
            $qtyDelta,
            $valueDeltaHalalas
        ) {
            $existing = InventoryMonthlyCost::where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->where('item_id', $itemId)
                ->where('year_month', $yearMonth)
                ->lockForUpdate()
                ->first();

            if ($existing && $existing->status === InventoryMonthlyCost::STATUS_APPROVED) {
                return [
                    'applied' => false,
                    'status' => 'pending_period_decision',
                    'monthly_cost' => $existing,
                ];
            }

            $openingQty = $existing ? (float) $existing->opening_qty : 0.0;
            $openingValueHalalas = $existing ? (int) $existing->opening_value_halalas : 0;
            $purchasesQty = round(max(0.0, ($existing ? (float) $existing->purchases_qty : 0.0) + $qtyDelta), 3);
            $purchasesValueHalalas = max(0, ($existing ? (int) $existing->purchases_value_halalas : 0) + $valueDeltaHalalas);
            $transfersInQty = $existing ? (float) $existing->transfers_in_qty : 0.0;
            $transfersInValueHalalas = $existing ? (int) $existing->transfers_in_value_halalas : 0;

            $totalQty = round($openingQty + $purchasesQty + $transfersInQty, 3);
            $totalValue = $openingValueHalalas + $purchasesValueHalalas + $transfersInValueHalalas;

            if ($purchasesQty <= 0.0 && $transfersInQty <= 0.0 && $openingQty > 0.0) {
                $unitCostHalalas = (int) round($openingValueHalalas / $openingQty);
            } elseif ($totalQty > 0.0) {
                $unitCostHalalas = (int) round($totalValue / $totalQty);
            } else {
                $unitCostHalalas = 0;
            }

            $revision = $existing ? ((int) $existing->revision + 1) : 1;

            // Persist directly via locked instance when present so MySQL REPEATABLE READ
            // does not issue a stale-snapshot SELECT inside updateOrCreate().
            $row = $this->persistLockedMonthlyCost(
                $existing,
                [
                    'company_id' => $companyId,
                    'branch_id' => $branchId,
                    'item_id' => $itemId,
                    'year_month' => $yearMonth,
                ],
                [
                    'opening_qty' => $openingQty,
                    'opening_value_halalas' => $openingValueHalalas,
                    'purchases_qty' => $purchasesQty,
                    'purchases_value_halalas' => $purchasesValueHalalas,
                    'transfers_in_qty' => $transfersInQty,
                    'transfers_in_value_halalas' => $transfersInValueHalalas,
                    'unit_cost_halalas' => $unitCostHalalas,
                    'status' => InventoryMonthlyCost::STATUS_PROVISIONAL,
                    'revision' => $revision,
                    'approved_by_id' => null,
                    'approved_at' => null,
                ]
            );

            return [
                'applied' => true,
                'status' => 'provisional_updated',
                'monthly_cost' => $row,
            ];
        });
    }

    private function persistLockedMonthlyCost(
        ?InventoryMonthlyCost $existing,
        array $keys,
        array $attributes
    ): InventoryMonthlyCost {
        if ($existing) {
            $existing->update($attributes);

            return $existing->fresh();
        }

        return InventoryMonthlyCost::create($keys + $attributes);
    }

    public function approve(string $companyId, string $branchId, string $itemId, string $yearMonth, string $actorId): InventoryMonthlyCost
    {
        $row = InventoryMonthlyCost::where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('item_id', $itemId)
            ->where('year_month', $yearMonth)
            ->firstOrFail();

        $row->update([
            'status' => InventoryMonthlyCost::STATUS_APPROVED,
            'approved_by_id' => $actorId,
            'approved_at' => now(),
        ]);

        return $row->fresh();
    }

    public function getApprovedUnitCost(string $companyId, string $branchId, string $itemId, string $yearMonth): ?InventoryMonthlyCost
    {
        return InventoryMonthlyCost::where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('item_id', $itemId)
            ->where('year_month', $yearMonth)
            ->where('status', InventoryMonthlyCost::STATUS_APPROVED)
            ->first();
    }
}
