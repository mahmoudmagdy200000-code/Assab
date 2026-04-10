<?php

namespace Modules\Inventory\Services;

use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Inventory\Models\InventorySession;
use Modules\Inventory\Models\MonthlyInventory;
use Modules\Inventory\Models\WasteDamageReport;
use Modules\Purchase\Models\BranchItem;

/**
 * Returns a unified list of "my tasks" (assignments) for the authenticated inventory actor.
 * Cashier: daily sessions assigned to them, monthly inventories where they are in staff, waste/damage reports assigned to them.
 * Branch Manager: optional branch summary or empty (main consumer is cashier).
 */
class InventoryTaskListService
{
    private const DEFAULT_LIMIT = 20;

    /**
     * @return array{daily_quick: array<int, array>, monthly: array<int, array>, waste_damage: array<int, array>}
     */
    public function getTasksForActor(BranchManager|Cashier $actor, int $limit = self::DEFAULT_LIMIT): array
    {
        $branchId = $actor->branch_id ?? null;
        if (!$branchId) {
            return [
                'daily_quick' => [],
                'monthly' => [],
                'waste_damage' => [],
            ];
        }

        if ($actor instanceof Cashier) {
            return [
                'daily_quick' => $this->getDailyQuickTasksForCashier($actor->id, $branchId, $limit),
                'monthly' => $this->getMonthlyTasksForCashier($actor->id, $branchId, $limit),
                'waste_damage' => $this->getWasteDamageTasksForCashier($actor->id, $branchId, $limit),
            ];
        }

        return [
            'daily_quick' => [],
            'monthly' => [],
            'waste_damage' => [],
        ];
    }

    /**
     * @return array<int, array{id: string, type: string, status: string, status_label: string, inventory_date: string|null, session_number: string, created_at: string, items: array}>
     */
    private function getDailyQuickTasksForCashier(string $cashierId, string $branchId, int $limit): array
    {
        $sessions = InventorySession::query()
            ->where('branch_id', $branchId)
            ->where('assigned_to_type', 'staff')
            ->where('assigned_to_id', $cashierId)
            ->with(['branch:id,name', 'assignedTo:id,name', 'items.item'])
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        // Batch-load BranchItems for all items across sessions
        $itemIds = $sessions->flatMap(fn ($s) => $s->items->pluck('item_id'))->unique()->values()->all();

        $branchItems = BranchItem::where('branch_id', $branchId)
            ->whereIn('item_id', $itemIds)
            ->get()
            ->keyBy('item_id');

        return $sessions->map(fn (InventorySession $s) => [
            'id' => $s->id,
            'type' => 'daily_quick',
            'status' => $s->status->value,
            'status_label' => $s->status_label,
            'inventory_date' => $s->inventory_date?->format('Y-m-d'),
            'session_number' => $s->session_number ?? '',
            'start_time' => $s->start_time?->format('Y-m-d H:i:s'),
            'created_at' => $s->created_at->toIso8601String(),
            'items' => $s->items->map(function ($inventoryItem) use ($branchItems) {
                $item = $inventoryItem->item;
                $branchItem = $branchItems->get($inventoryItem->item_id);
                return [
                    'id' => $inventoryItem->id,
                    'branch_item_id' => $branchItem?->id,
                    'item_id' => $inventoryItem->item_id,
                    'item_name' => $item?->name ?? $inventoryItem->item_name,
                    'item_code' => $item?->code,
                    'item_logo' => $item?->logo_url,
                    'item_unit' => $item?->unit,
                    'category' => $item?->category,
                    'subcategory' => $item?->subcategory,
                    'price' => $branchItem ? (float) $branchItem->price : null,
                    'locked_at' => null,
                ];
            })->values()->all(),
        ])->values()->all();
    }

    /**
     * @return array<int, array{id: string, type: string, status: string, status_label: string, inventory_date: string|null, inventory_number: string, products_count: int, inventoried_count: int, created_at: string}>
     */
    private function getMonthlyTasksForCashier(string $cashierId, string $branchId, int $limit): array
    {
        return MonthlyInventory::query()
            ->where('branch_id', $branchId)
            ->whereHas('staff', function ($q) use ($cashierId) {
                $q->where('user_id', $cashierId)->whereIn('user_type', [(new Cashier)->getMorphClass(), Cashier::class]);
            })
            ->with(['branch:id,name'])
            ->withCount([
                'products',
                'products as inventoried_count' => function ($q) {
                    $q->whereNotNull('counted_by_id');
                },
            ])
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (MonthlyInventory $m) => [
                'id' => $m->id,
                'type' => 'monthly',
                'status' => $m->status->value,
                'status_label' => $m->status_label,
                'inventory_date' => $m->inventory_date?->format('Y-m-d'),
                'inventory_number' => $m->inventory_number ?? '',
                'products_count' => $m->products_count,
                'inventoried_count' => $m->inventoried_count,
                'created_at' => $m->created_at->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{id: string, type: string, status: string, status_label: string, created_at: string}>
     */
    private function getWasteDamageTasksForCashier(string $cashierId, string $branchId, int $limit): array
    {
        return WasteDamageReport::query()
            ->where('branch_id', $branchId)
            ->where('assigned_to_id', $cashierId)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (WasteDamageReport $r) => [
                'id' => $r->id,
                'type' => 'waste_damage',
                'status' => $r->status->value,
                'status_label' => $r->status->listLabel(),
                'created_at' => $r->created_at->toIso8601String(),
            ])
            ->values()
            ->all();
    }
}
