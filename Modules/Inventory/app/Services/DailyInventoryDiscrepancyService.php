<?php

namespace Modules\Inventory\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\Enums\InventorySessionStatus;
use Modules\Inventory\Models\DailyInventoryDiscrepancy;
use Modules\Inventory\Models\InventoryItem;
use Modules\Inventory\Models\InventorySession;
use Modules\Purchase\Enums\OrderType;
use Modules\Purchase\Models\BranchItem;
use Modules\Purchase\Models\GoodsReceiptItem;
use Modules\Purchase\Models\PurchaseOrderItem;

class DailyInventoryDiscrepancyService
{
    /**
     * Calculate discrepancy for each item and persist. Returns true if any discrepancy, false if all matching.
     */
    public function calculateAndStore(InventorySession $session): bool
    {
        $session->load('items.item');
        $branchId = $session->branch_id;
        $inventoryDate = $session->inventory_date;
        $dateFrom = $inventoryDate->copy()->startOfDay();
        $dateTo = $inventoryDate->copy()->endOfDay();

        $hasDiscrepancy = false;

        foreach ($session->items as $invItem) {
            $itemId = $invItem->item_id;
            if (!$itemId) {
                continue;
            }

            $openingBalance = $this->getOpeningBalance($branchId, $itemId, $inventoryDate);
            $purchases = $this->getPurchases($branchId, $itemId, $dateFrom, $dateTo);
            $sales = (float) ($invItem->sales_quantity ?? 0);
            $recordedWaste = (float) ($invItem->recorded_waste ?? 0);
            $transferIn = $this->getTransferIn($branchId, $itemId, $dateFrom, $dateTo);
            $transferOut = $this->getTransferOut($branchId, $itemId, $dateFrom, $dateTo);
            $actual = (float) $invItem->quantity_inventory;

            $theoreticallyExpected = ($openingBalance + $purchases + $transferIn - $transferOut) - $sales - $recordedWaste;
            $difference = $theoreticallyExpected - $actual;
            $isMatch = abs($difference) < 0.001;

            if (!$isMatch) {
                $hasDiscrepancy = true;
            }

            $unitPrice = BranchItem::where('branch_id', $session->branch_id)
                ->where('item_id', $itemId)
                ->value('price');
            $differenceValueSar = $unitPrice ? abs($difference) * (float) $unitPrice : null;
            $discrepancyType = $difference > 0 ? 'shortage' : 'over';

            DailyInventoryDiscrepancy::updateOrCreate(
                [
                    'inventory_session_id' => $session->id,
                    'inventory_item_id' => $invItem->id,
                ],
                [
                    'item_id' => $itemId,
                    'opening_balance' => $openingBalance,
                    'purchases' => $purchases,
                    'sales' => $sales,
                    'recorded_waste' => $recordedWaste,
                    'net_transfer_in' => $transferIn,
                    'net_transfer_out' => $transferOut,
                    'theoretically_expected' => $theoreticallyExpected,
                    'actual' => $actual,
                    'difference_quantity' => $difference,
                    'difference_value_sar' => $differenceValueSar,
                    'discrepancy_type' => $isMatch ? null : $discrepancyType,
                ]
            );
        }

        return $hasDiscrepancy;
    }

    protected function getOpeningBalance(string $branchId, string $itemId, Carbon $beforeDate): float
    {
        $lastSession = InventorySession::where('branch_id', $branchId)
            ->whereIn('status', [InventorySessionStatus::APPROVED, InventorySessionStatus::COMPLETED])
            ->whereDate('inventory_date', '<', $beforeDate)
            ->orderByDesc('inventory_date')
            ->first();

        if (!$lastSession) {
            return 0;
        }

        $item = InventoryItem::where('inventory_session_id', $lastSession->id)
            ->where('item_id', $itemId)
            ->first();

        return $item ? (float) $item->quantity_inventory : 0;
    }

    protected function getPurchases(string $branchId, string $itemId, Carbon $from, Carbon $to): float
    {
        return (float) GoodsReceiptItem::query()
            ->whereHas('goodsReceipt', function ($q) use ($branchId, $from, $to) {
                $q->where('branch_id', $branchId)
                    ->whereIn('status', ['completed', 'has_variance'])
                    ->whereBetween('inspection_completed_at', [$from, $to]);
            })
            ->where('item_id', $itemId)
            ->sum('quantity_received');
    }

    protected function getTransferIn(string $branchId, string $itemId, Carbon $from, Carbon $to): float
    {
        return (float) GoodsReceiptItem::query()
            ->whereHas('goodsReceipt.purchaseOrder', function ($q) use ($branchId, $from, $to) {
                $q->where('to_branch_id', $branchId)
                    ->where('order_type', OrderType::INTERNAL_TRANSFER)
                    ->whereBetween('received_at', [$from, $to]);
            })
            ->where('item_id', $itemId)
            ->sum('quantity_received');
    }

    protected function getTransferOut(string $branchId, string $itemId, Carbon $from, Carbon $to): float
    {
        return (float) PurchaseOrderItem::query()
            ->whereHas('purchaseOrder', function ($q) use ($branchId, $from, $to) {
                $q->where('from_branch_id', $branchId)
                    ->where('order_type', OrderType::INTERNAL_TRANSFER)
                    ->whereBetween('received_at', [$from, $to]);
            })
            ->where('item_id', $itemId)
            ->sum('quantity_received');
    }
}
