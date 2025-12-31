<?php

namespace Modules\Inventory\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Inventory\Enums\InventorySessionStatus;
use Modules\Inventory\Models\InventoryItem;
use Modules\Inventory\Models\InventorySession;
use Modules\Purchase\Enums\OrderStatus;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Models\PurchaseOrderItem;

class InventorySessionService
{
    /**
     * Get all purchase order items from closed orders for a branch
     *
     * @param string $branchId
     * @return Collection
     */
    public function getClosedOrderItems(string $branchId): Collection
    {
        return PurchaseOrderItem::with([
            'purchaseOrder:id,order_number,status,closed_at',
            'item:id,name,code,logo,unit,category,subcategory',
        ])
            ->whereHas('purchaseOrder', function ($query) use ($branchId) {
                $query->where('branch_id', $branchId)
                    ->where('status', OrderStatus::CLOSED);
            })
            ->get()
            ->map(function ($item) {
                return [
                    'id' => $item->id,
                    'purchase_order_item_id' => $item->id,
                    'purchase_order_id' => $item->purchase_order_id,
                    'order_number' => $item->purchaseOrder->order_number ?? null,
                    'item_id' => $item->item_id,
                    'item_name' => $item->item_name,
                    'item_code' => $item->item->code ?? null,
                    'item_logo' => $item->item->logo ?? null,
                    'item_unit' => $item->item->unit ?? null,
                    'category' => $item->category,
                    'subcategory' => $item->subcategory,
                    'quantity_ordered' => $item->quantity_ordered,
                    'quantity_received' => $item->quantity_received,
                    'unit_price' => $item->unit_price,
                    'closed_at' => $item->purchaseOrder->closed_at ?? null,
                ];
            });
    }

    /**
     * Get available cashiers for a branch
     *
     * @param string $branchId
     * @return Collection
     */
    public function getAvailableCashiers(string $branchId): Collection
    {
        return Cashier::where('branch_id', $branchId)
            ->where('status', 'active')
            ->select(['id', 'name', 'email', 'phone', 'image'])
            ->get();
    }

    /**
     * Create a draft inventory session
     *
     * @param array $data
     * @param BranchManager $manager
     * @return InventorySession
     */
    public function createDraft(array $data, BranchManager $manager): InventorySession
    {
        return DB::transaction(function () use ($data, $manager) {
            $sessionData = [
                'branch_id' => $manager->branch_id,
                'created_by' => $manager->id,
                'assigned_to_type' => $data['assigned_to_type'],
                'assigned_to_id' => $data['assigned_to_type'] === 'staff' ? $data['assigned_to_id'] : null,
                'inventory_date' => $data['inventory_date'],
                'start_time' => $data['start_time'],
                'notes' => $data['notes'] ?? null,
                'status' => InventorySessionStatus::DRAFT,
            ];

            // Validate assigned cashier belongs to same branch
            if ($sessionData['assigned_to_id']) {
                $cashier = Cashier::where('id', $sessionData['assigned_to_id'])
                    ->where('branch_id', $manager->branch_id)
                    ->where('status', 'active')
                    ->firstOrFail();
            }

            $session = InventorySession::create($sessionData);

            // Add items if provided
            if (!empty($data['items']) && is_array($data['items'])) {
                foreach ($data['items'] as $itemData) {
                    $this->addItemToSession(
                        $session->id,
                        $itemData['item_id'],
                        $manager,
                        $itemData['quantity'] ?? 0
                    );
                }
            }

            return $session->fresh(['items.item', 'items.purchaseOrderItem.purchaseOrder']);
        });
    }

    /**
     * Add item to session (internal helper method)
     *
     * @param string $sessionId
     * @param string $purchaseOrderItemId
     * @param BranchManager $manager
     * @param float $quantity
     * @return InventoryItem
     */
    private function addItemToSession(string $sessionId, string $purchaseOrderItemId, BranchManager $manager, float $quantity = 0): InventoryItem
    {
        // Get purchase order item
        $purchaseOrderItem = PurchaseOrderItem::with('purchaseOrder')
            ->where('id', $purchaseOrderItemId)
            ->whereHas('purchaseOrder', function ($query) use ($manager) {
                $query->where('branch_id', $manager->branch_id)
                    ->where('status', OrderStatus::CLOSED);
            })
            ->firstOrFail();

        // Check if item already exists in session
        $existingItem = InventoryItem::where('inventory_session_id', $sessionId)
            ->where('purchase_order_item_id', $purchaseOrderItemId)
            ->first();

        if ($existingItem) {
            // Item already exists, skip
            return $existingItem;
        }

        return InventoryItem::create([
            'inventory_session_id' => $sessionId,
            'purchase_order_item_id' => $purchaseOrderItem->id,
            'item_id' => $purchaseOrderItem->item_id,
            'item_name' => $purchaseOrderItem->item_name,
            'quantity_inventory' => $quantity,
            'notes' => null,
            'branch_id' => $manager->branch_id,
        ]);
    }

    /**
     * Update a draft inventory session
     *
     * @param string $sessionId
     * @param array $data
     * @param BranchManager $manager
     * @return InventorySession
     */
    public function updateDraft(string $sessionId, array $data, BranchManager $manager): InventorySession
    {
        return DB::transaction(function () use ($sessionId, $data, $manager) {
            $session = InventorySession::where('id', $sessionId)
                ->where('branch_id', $manager->branch_id)
                ->where('created_by', $manager->id)
                ->where('status', InventorySessionStatus::DRAFT)
                ->firstOrFail();

            $updateData = [];

            if (isset($data['inventory_date'])) {
                $updateData['inventory_date'] = $data['inventory_date'];
            }

            if (isset($data['start_time'])) {
                $updateData['start_time'] = $data['start_time'];
            }

            if (isset($data['notes'])) {
                $updateData['notes'] = $data['notes'];
            }

            $session->update($updateData);

            return $session->fresh();
        });
    }

    /**
     * Submit inventory session (calculate time and complete)
     *
     * @param string $sessionId
     * @param BranchManager $manager
     * @return InventorySession
     */
    public function submitSession(string $sessionId, BranchManager $manager): InventorySession
    {
        return DB::transaction(function () use ($sessionId, $manager) {
            $session = InventorySession::where('id', $sessionId)
                ->where('branch_id', $manager->branch_id)
                ->where('created_by', $manager->id)
                ->where('status', InventorySessionStatus::DRAFT)
                ->firstOrFail();

            // Ensure session has items
            if ($session->items()->count() === 0) {
                throw new \InvalidArgumentException('Cannot submit session without items');
            }

            $session->complete();

            return $session->fresh(['items.item', 'items.purchaseOrderItem.purchaseOrder']);
        });
    }

    /**
     * Add item to inventory session
     *
     * @param string $sessionId
     * @param array $itemData
     * @param BranchManager $manager
     * @return InventoryItem
     */
    public function addItem(string $sessionId, array $itemData, BranchManager $manager): InventoryItem
    {
        return DB::transaction(function () use ($sessionId, $itemData, $manager) {
            $session = InventorySession::where('id', $sessionId)
                ->where('branch_id', $manager->branch_id)
                ->where('created_by', $manager->id)
                ->where('status', InventorySessionStatus::DRAFT)
                ->firstOrFail();

            // Get purchase order item
            $purchaseOrderItem = PurchaseOrderItem::with('purchaseOrder')
                ->where('id', $itemData['purchase_order_item_id'])
                ->whereHas('purchaseOrder', function ($query) use ($manager) {
                    $query->where('branch_id', $manager->branch_id)
                        ->where('status', OrderStatus::CLOSED);
                })
                ->firstOrFail();

            // Check if item already exists in session
            $existingItem = InventoryItem::where('inventory_session_id', $sessionId)
                ->where('purchase_order_item_id', $itemData['purchase_order_item_id'])
                ->first();

            if ($existingItem) {
                throw new \InvalidArgumentException('Item already exists in this session');
            }

            return InventoryItem::create([
                'inventory_session_id' => $sessionId,
                'purchase_order_item_id' => $purchaseOrderItem->id,
                'item_id' => $purchaseOrderItem->item_id,
                'item_name' => $purchaseOrderItem->item_name,
                'quantity_inventory' => $itemData['quantity_inventory'],
                'notes' => $itemData['notes'] ?? null,
                'branch_id' => $manager->branch_id,
            ]);
        });
    }

    /**
     * Update inventory item
     *
     * @param string $itemId
     * @param array $data
     * @param BranchManager $manager
     * @return InventoryItem
     */
    public function updateItem(string $itemId, array $data, BranchManager $manager): InventoryItem
    {
        return DB::transaction(function () use ($itemId, $data, $manager) {
            $item = InventoryItem::whereHas('inventorySession', function ($query) use ($manager) {
                $query->where('branch_id', $manager->branch_id)
                    ->where('created_by', $manager->id)
                    ->where('status', InventorySessionStatus::DRAFT);
            })
                ->where('id', $itemId)
                ->firstOrFail();

            $updateData = [];

            if (isset($data['quantity_inventory'])) {
                $updateData['quantity_inventory'] = $data['quantity_inventory'];
            }

            if (isset($data['notes'])) {
                $updateData['notes'] = $data['notes'];
            }

            $item->update($updateData);

            return $item->fresh(['item', 'purchaseOrderItem.purchaseOrder']);
        });
    }

    /**
     * Remove item from inventory session
     *
     * @param string $itemId
     * @param BranchManager $manager
     * @return bool
     */
    public function removeItem(string $itemId, BranchManager $manager): bool
    {
        return DB::transaction(function () use ($itemId, $manager) {
            $item = InventoryItem::whereHas('inventorySession', function ($query) use ($manager) {
                $query->where('branch_id', $manager->branch_id)
                    ->where('created_by', $manager->id)
                    ->where('status', InventorySessionStatus::DRAFT);
            })
                ->where('id', $itemId)
                ->firstOrFail();

            return $item->delete();
        });
    }

    /**
     * Get session summary
     *
     * @param string $sessionId
     * @param BranchManager $manager
     * @return array
     */
    public function getSessionSummary(string $sessionId, BranchManager $manager): array
    {
        $session = InventorySession::with([
            'items.item',
            'items.purchaseOrderItem.purchaseOrder',
        ])
            ->where('id', $sessionId)
            ->where('branch_id', $manager->branch_id)
            ->where('created_by', $manager->id)
            ->firstOrFail();

        $items = $session->items()->with(['item', 'purchaseOrderItem.purchaseOrder'])->get();

        return [
            'session_summary' => [
                'inventory_date' => $session->inventory_date->format('Y-m-d'),
                'start_time' => $session->start_time->format('Y-m-d H:i:s'),
                'end_time' => $session->end_time?->format('Y-m-d H:i:s'),
                'time_taken' => $session->time_taken_formatted,
            ],
            'all_inventoried_products' => $items->map(function ($item) {
                return [
                    'item_name' => $item->item_name,
                    'quantity' => (float) $item->quantity_inventory,
                    'notes' => $item->notes,
                ];
            })->toArray(),
        ];
    }
}
