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

            $revision = $existing ? ((int) $existing->revision + 1) : 1;

            return InventoryMonthlyCost::updateOrCreate(
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
                    'status' => $status,
                    'revision' => $revision,
                    'approved_by_id' => $status === InventoryMonthlyCost::STATUS_APPROVED ? $approvedById : null,
                    'approved_at' => $status === InventoryMonthlyCost::STATUS_APPROVED ? now() : null,
                ]
            );
        });
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
