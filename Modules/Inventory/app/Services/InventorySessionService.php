<?php

namespace Modules\Inventory\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Inventory\Enums\DailyInventoryTimelineEventType;
use Modules\Inventory\Enums\InventorySessionStatus;
use Modules\Inventory\Models\InventoryItem;
use Modules\Inventory\Models\InventorySession;
use Modules\Inventory\Models\InventorySessionTimeline;
use Modules\Purchase\Enums\OrderStatus;
use Modules\Purchase\Models\BranchItem;
use Modules\Purchase\Models\PurchaseOrderItem;

class InventorySessionService
{
    /**
     * Get branch items (from branch purchase configuration) for daily inventory.
     * Items are only from those assigned to the branch (branch_item).
     * By default excludes items that are in an ACTIVE session (draft/pending/pending_your_action/pending_your_confirmation).
     * Items from completed, approved, or rejected sessions are always available again.
     *
     * @param  bool  $includeAll  When true, return all branch items without any exclusion.
     */
    public function getBranchItems(string $branchId, bool $includeAll = false): Collection
    {
        // whereHas('item'): a soft-deleted catalog item (deactivated on the
        // dashboard while branch stock != 0) leaves a ghost branch_item whose
        // every display field is null — the mobile app casts them to String.
        $query = BranchItem::with(['item:id,name,code,logo,unit,category,subcategory'])
            ->where('branch_id', $branchId)
            ->whereHas('item');

        if (! $includeAll) {
            $activeStatuses = [
                InventorySessionStatus::DRAFT->value,
                InventorySessionStatus::PENDING->value,
                InventorySessionStatus::PENDING_YOUR_ACTION->value,
                InventorySessionStatus::PENDING_YOUR_CONFIRMATION->value,
            ];

            $itemIdsInActiveSessions = InventoryItem::where('branch_id', $branchId)
                ->whereHas('inventorySession', fn ($q) => $q->whereIn('status', $activeStatuses))
                ->select('item_id')
                ->distinct()
                ->pluck('item_id');

            $query->whereNotIn('item_id', $itemIdsInActiveSessions);
        }

        return $query
            ->get()
            ->sortBy(fn ($bi) => $bi->item?->name ?? '')
            ->values()
            ->map(function ($branchItem) {
                return [
                    'id' => $branchItem->id,
                    'branch_item_id' => $branchItem->id,
                    'item_id' => $branchItem->item_id,
                    // Uploaded catalog rows carry NULL code/unit/category and the
                    // app casts these to String — coalesce like BranchItemResource.
                    'item_name' => $branchItem->item?->name ?? '',
                    'item_code' => $branchItem->item?->code ?? '',
                    'item_logo' => $branchItem->item?->logo_url ?? '',
                    'item_unit' => $branchItem->item?->unit ?? 'kg',
                    'category' => $branchItem->item?->category ?? '',
                    'subcategory' => $branchItem->item?->subcategory ?? '',
                    'price' => $branchItem->price,
                    'quantity' => $branchItem->quantity,
                ];
            });
    }

    /**
     * Get all purchase order items from closed orders for a branch
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
                    'order_number' => $item->purchaseOrder->order_number ?? '',
                    'item_id' => $item->item_id,
                    'item_name' => $item->item_name ?? $item->item?->name ?? '',
                    'item_code' => $item->item?->code ?? '',
                    'item_logo' => $item->item?->logo_url ?? '',
                    'item_unit' => $item->item?->unit ?? 'kg',
                    'category' => $item->category ?? '',
                    'subcategory' => $item->subcategory ?? '',
                    'quantity_ordered' => $item->quantity_ordered,
                    'quantity_received' => $item->quantity_received,
                    'unit_price' => $item->unit_price,
                    'closed_at' => $item->purchaseOrder->closed_at ?? null,
                ];
            });
    }

    /**
     * Get available cashiers for a branch
     */
    public function getAvailableCashiers(string $branchId): Collection
    {
        return Cashier::byBranch($branchId)
            ->active()
            ->select(['id', 'name', 'email', 'phone', 'image'])
            ->orderBy('name')
            ->get();
    }

    /**
     * Create a draft inventory session
     *
     * @param  BranchManager  $manager
     */
    public function createDraft(array $data, BranchManager|Cashier $creator): InventorySession
    {
        return DB::transaction(function () use ($data, $creator) {
            $creatorType = $creator instanceof Cashier ? 'cashier' : 'branch_manager';

            // Cashier: reuse existing active session assigned to them by branch manager (no duplicate session).
            if ($creator instanceof Cashier) {
                $existingAssigned = InventorySession::where('branch_id', $creator->branch_id)
                    ->where('assigned_to_type', 'staff')
                    ->where('assigned_to_id', $creator->id)
                    ->whereIn('status', [
                        InventorySessionStatus::PENDING,
                        InventorySessionStatus::DRAFT,
                        InventorySessionStatus::PENDING_YOUR_ACTION,
                    ])
                    ->whereNull('submitted_at')
                    ->orderByDesc('created_at')
                    ->first();

                if ($existingAssigned) {
                    $itemsProvided = ! empty($data['items']) && is_array($data['items']);
                    if ($itemsProvided) {
                        foreach ($data['items'] as $itemData) {
                            $item = $this->addItemToSessionByIdentifier(
                                $existingAssigned->id,
                                $itemData['item_id'],
                                $creator,
                                $itemData['quantity'] ?? 0,
                                $itemData['notes'] ?? null
                            );

                            if (isset($itemData['quantity']) || array_key_exists('notes', $itemData)) {
                                $updates = [];
                                if (isset($itemData['quantity'])) {
                                    $updates['quantity_inventory'] = $itemData['quantity'];
                                }
                                if (array_key_exists('notes', $itemData)) {
                                    $updates['notes'] = $itemData['notes'];
                                }
                                if ($updates) {
                                    $item->update($updates);
                                }
                            }
                        }

                        // Cashier's POST /sessions is the full submission — mark submitted so
                        // isStaffInventored=true and Branch Manager can confirm.
                        if ($existingAssigned->submitted_at === null) {
                            $oldStatus = $existingAssigned->status->value;
                            $existingAssigned->end_time = now();
                            $existingAssigned->calculateTimeTaken();
                            $existingAssigned->status = InventorySessionStatus::PENDING;
                            $existingAssigned->submitted_at = now();
                            $existingAssigned->save();

                            InventorySessionTimeline::log(
                                $existingAssigned,
                                DailyInventoryTimelineEventType::SUBMITTED,
                                'Submitted',
                                'Staff submitted this daily inventory for Branch Manager confirmation.',
                                $oldStatus,
                                InventorySessionStatus::PENDING->value
                            );
                        }
                    }

                    return $existingAssigned->fresh(['items.item', 'items.purchaseOrderItem.purchaseOrder']);
                }

                // No assigned session — cashier may only create personal sessions.
                $data['assigned_to_type'] = 'personal';
            }

            $assignedToType = $data['assigned_to_type'];
            $isStaffAssignment = $assignedToType === 'staff' && $creator instanceof BranchManager;

            $sessionData = [
                'branch_id' => $creator->branch_id,
                'created_by' => $creator->id,
                'created_by_type' => $creatorType,
                'assigned_to_type' => $assignedToType,
                'assigned_to_id' => $isStaffAssignment ? $data['assigned_to_id'] : null,
                'inventory_date' => $data['inventory_date'] ?? null,
                'start_time' => $data['start_time'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => InventorySessionStatus::PENDING,
            ];

            // Validate assigned cashier belongs to same branch (only managers can assign staff)
            if ($sessionData['assigned_to_id']) {
                $cashier = Cashier::where('id', $sessionData['assigned_to_id'])
                    ->where('branch_id', $creator->branch_id)
                    ->where('status', 'active')
                    ->firstOrFail();
            }

            $session = InventorySession::create($sessionData);

            // Add items if provided (item_id can be purchase_order_item id or item id from getBranchItems)
            if (! empty($data['items']) && is_array($data['items'])) {
                foreach ($data['items'] as $itemData) {
                    $this->addItemToSessionByIdentifier(
                        $session->id,
                        $itemData['item_id'],
                        $creator,
                        $itemData['quantity'] ?? 0,
                        $itemData['notes'] ?? null
                    );
                }
            }

            InventorySessionTimeline::log(
                $session,
                DailyInventoryTimelineEventType::CREATED,
                'Created',
                'Daily inventory session created.',
                null,
                InventorySessionStatus::PENDING->value
            );

            return $session->fresh(['items.item', 'items.purchaseOrderItem.purchaseOrder']);
        });
    }

    /**
     * Add item to session by identifier (purchase_order_item id or item id from getBranchItems).
     *
     * @param  string  $identifier  Either purchase_order_items.id or items.id (from branch_items)
     * @param  BranchManager  $manager
     */
    private function addItemToSessionByIdentifier(string $sessionId, string $identifier, BranchManager|Cashier $actor, float $quantity = 0, ?string $notes = null): InventoryItem
    {
        $branchId = $actor->branch_id;

        $purchaseOrderItem = PurchaseOrderItem::with('purchaseOrder')
            ->where('id', $identifier)
            ->whereHas('purchaseOrder', function ($query) use ($branchId): void {
                $query->where('branch_id', $branchId)
                    ->where('status', OrderStatus::CLOSED);
            })
            ->first();

        if ($purchaseOrderItem) {
            return $this->addItemToSession($sessionId, $purchaseOrderItem, $branchId, $quantity, $notes);
        }

        $branchItem = BranchItem::with('item')
            ->where('branch_id', $branchId)
            ->where('item_id', $identifier)
            ->firstOrFail();

        $existingItem = InventoryItem::where('inventory_session_id', $sessionId)
            ->where('item_id', $identifier)
            ->whereNull('purchase_order_item_id')
            ->first();

        if ($existingItem) {
            return $existingItem;
        }

        return InventoryItem::create([
            'inventory_session_id' => $sessionId,
            'purchase_order_item_id' => null,
            'item_id' => $branchItem->item_id,
            'item_name' => $branchItem->item_name ?? $branchItem->item?->name ?? '',
            'quantity_inventory' => $quantity,
            'notes' => $notes,
            'branch_id' => $branchId,
        ]);
    }

    /**
     * Add item to session from a purchase order item (internal helper).
     */
    private function addItemToSession(string $sessionId, PurchaseOrderItem $purchaseOrderItem, string $branchId, float $quantity = 0, ?string $notes = null): InventoryItem
    {
        $existingItem = InventoryItem::where('inventory_session_id', $sessionId)
            ->where('purchase_order_item_id', $purchaseOrderItem->id)
            ->first();

        if ($existingItem) {
            return $existingItem;
        }

        return InventoryItem::create([
            'inventory_session_id' => $sessionId,
            'purchase_order_item_id' => $purchaseOrderItem->id,
            'item_id' => $purchaseOrderItem->item_id,
            'item_name' => $purchaseOrderItem->item_name,
            'quantity_inventory' => $quantity,
            'notes' => $notes,
            'branch_id' => $branchId,
        ]);
    }

    /**
     * Update a draft inventory session
     */
    public function updateDraft(string $sessionId, array $data, BranchManager $manager): InventorySession
    {
        return DB::transaction(function () use ($sessionId, $data, $manager) {
            $session = InventorySession::where('id', $sessionId)
                ->where('branch_id', $manager->branch_id)
                ->whereIn('status', [InventorySessionStatus::DRAFT, InventorySessionStatus::PENDING])
                ->where(function ($q) use ($manager) {
                    $q->whereNull('created_by')->orWhere('created_by', $manager->id);
                })
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
     * Submit inventory session. Sets status to Pending (awaiting Account Manager approval).
     * Allowed for Branch Manager (creator) or Cashier (assigned to session).
     */
    public function submitSession(string $sessionId, BranchManager|Cashier $actor): InventorySession
    {
        $session = DB::transaction(function () use ($sessionId, $actor) {
            $query = InventorySession::where('id', $sessionId)
                ->where('branch_id', $actor->branch_id);

            if ($actor instanceof Cashier) {
                $query->where('assigned_to_type', 'staff')
                    ->where('assigned_to_id', $actor->id)
                    ->where('status', InventorySessionStatus::PENDING)
                    ->whereNull('submitted_at');
            } else {
                $query->whereIn('status', [InventorySessionStatus::DRAFT, InventorySessionStatus::PENDING])
                    ->whereNull('submitted_at')
                    ->where(function ($q) use ($actor) {
                        $q->whereNull('created_by')->orWhere('created_by', $actor->id);
                    });
            }

            $session = $query->firstOrFail();

            if ($session->items()->count() === 0) {
                throw new \InvalidArgumentException('Cannot submit session without items');
            }

            $oldStatus = $session->status->value;
            $newStatus = InventorySessionStatus::PENDING;

            $session->end_time = now();
            $session->calculateTimeTaken();
            $session->status = $newStatus;
            $session->submitted_at = now();
            $session->save();

            InventorySessionTimeline::log(
                $session,
                DailyInventoryTimelineEventType::SUBMITTED,
                'Submitted',
                $actor instanceof Cashier
                    ? 'Staff submitted this daily inventory for Branch Manager confirmation.'
                    : 'You submitted this daily inventory for review and approval.',
                $oldStatus,
                $newStatus->value
            );

            return $session->fresh(['items.item', 'items.purchaseOrderItem.purchaseOrder']);
        });

        // AFTER commit: bridge to the ASAB accountant inbox (INV- operation).
        event(new \Modules\Inventory\Events\InventorySessionSubmittedEvent($session));

        return $session;
    }

    /**
     * Confirm cashier's submission (Branch Manager). Session stays PENDING; sets
     * manager_confirmed_at so Account Manager / Brand Owner picks it up.
     *
     * On confirm, a multi-item session is split into one session per item: the original
     * session keeps the first item, and (N-1) clones are created with the same shared
     * metadata (branch, creator, assignment, inventory_date, submitted_at). Each item is
     * reassigned to its own session so the Brand Owner sees one request per item.
     *
     * @param  array<int, array{itemId: string, quantity: float|int}>  $itemOverrides  Final quantities the manager wants applied before confirming.
     * @return \Illuminate\Support\Collection<int, InventorySession>
     */
    public function confirmCashierSubmission(string $sessionId, BranchManager $manager, array $itemOverrides = []): \Illuminate\Support\Collection
    {
        return DB::transaction(function () use ($sessionId, $manager, $itemOverrides) {
            // Gate: staff submission awaiting Branch Manager confirmation (isStaffInventored=true).
            // Discrepancy-review flow uses status=PENDING_YOUR_CONFIRMATION and is handled separately.
            $session = InventorySession::where('id', $sessionId)
                ->where('branch_id', $manager->branch_id)
                ->where('assigned_to_type', 'staff')
                ->whereNotNull('submitted_at')
                ->whereNull('manager_confirmed_at')
                ->whereIn('status', [InventorySessionStatus::PENDING, InventorySessionStatus::DRAFT])
                ->firstOrFail();

            if (! empty($itemOverrides)) {
                $sessionItemIds = $session->items()->pluck('id')->all();
                foreach ($itemOverrides as $override) {
                    $itemId = $override['itemId'] ?? null;
                    if (! $itemId || ! in_array($itemId, $sessionItemIds, true)) {
                        throw new \InvalidArgumentException("Item {$itemId} does not belong to this session.");
                    }
                    InventoryItem::where('id', $itemId)
                        ->update(['quantity_inventory' => $override['quantity']]);
                }
            }

            $items = $session->items()->get();
            if ($items->isEmpty()) {
                throw new \InvalidArgumentException('Session has no items to confirm.');
            }

            $relations = ['items.item', 'items.purchaseOrderItem.purchaseOrder', 'assignedTo', 'createdBy'];
            $confirmed = collect();
            $confirmedAt = now();

            // First item stays in the original session.
            $items->shift();
            $session->manager_confirmed_at = $confirmedAt;
            $session->save();
            InventorySessionTimeline::log(
                $session,
                DailyInventoryTimelineEventType::SUBMITTED,
                'Confirmed by Branch Manager',
                'Branch Manager confirmed staff submission. Sent to Account Manager for approval.',
                InventorySessionStatus::PENDING->value,
                InventorySessionStatus::PENDING->value
            );
            $confirmed->push($session->fresh($relations));

            // Remaining items each get their own cloned session.
            foreach ($items as $item) {
                $clone = InventorySession::create([
                    'branch_id' => $session->branch_id,
                    'created_by' => $session->created_by,
                    'created_by_type' => $session->created_by_type,
                    'assigned_to_type' => $session->assigned_to_type,
                    'assigned_to_id' => $session->assigned_to_id,
                    'inventory_date' => $session->inventory_date,
                    'start_time' => $session->start_time,
                    'end_time' => $session->end_time,
                    'time_taken' => $session->time_taken,
                    'status' => InventorySessionStatus::PENDING,
                    'notes' => $session->notes,
                    'submitted_at' => $session->submitted_at,
                    'manager_confirmed_at' => $confirmedAt,
                ]);

                $item->inventory_session_id = $clone->id;
                $item->save();

                InventorySessionTimeline::log(
                    $clone,
                    DailyInventoryTimelineEventType::CREATED,
                    'Created',
                    'Daily inventory session created from confirmation split.',
                    null,
                    InventorySessionStatus::PENDING->value
                );
                InventorySessionTimeline::log(
                    $clone,
                    DailyInventoryTimelineEventType::SUBMITTED,
                    'Confirmed by Branch Manager',
                    'Branch Manager confirmed staff submission. Sent to Account Manager for approval.',
                    InventorySessionStatus::PENDING->value,
                    InventorySessionStatus::PENDING->value
                );

                $confirmed->push($clone->fresh($relations));
            }

            return $confirmed;
        });
    }

    /**
     * Reject session (Account Manager). Status → Rejected; Branch Manager can edit and resubmit.
     */
    public function rejectSession(string $sessionId, string $comment): InventorySession
    {
        return DB::transaction(function () use ($sessionId, $comment) {
            $session = InventorySession::where('id', $sessionId)->firstOrFail();
            if ($session->status !== InventorySessionStatus::PENDING) {
                throw new \InvalidArgumentException('Only pending sessions can be rejected.');
            }
            if ($session->assigned_to_type === 'staff' && $session->manager_confirmed_at === null) {
                throw new \InvalidArgumentException('Branch Manager must confirm staff submission before Account Manager can act.');
            }

            $actor = auth()->user();
            $session->status = InventorySessionStatus::REJECTED;
            $session->rejected_at = now();
            $session->rejected_by = $actor?->getKey();
            $session->rejection_comment = $comment;
            $session->save();

            InventorySessionTimeline::log(
                $session,
                DailyInventoryTimelineEventType::REJECTED,
                'Rejected by Account Manager',
                $comment,
                InventorySessionStatus::PENDING->value,
                InventorySessionStatus::REJECTED->value
            );

            return $session->fresh();
        });
    }

    /**
     * Resubmit session after rejection (Branch Manager). Status → Pending.
     */
    public function resubmitSession(string $sessionId, BranchManager $manager): InventorySession
    {
        return DB::transaction(function () use ($sessionId, $manager) {
            $session = InventorySession::where('id', $sessionId)
                ->where('branch_id', $manager->branch_id)
                ->where('status', InventorySessionStatus::REJECTED)
                ->firstOrFail();

            $session->status = InventorySessionStatus::PENDING;
            $session->rejected_at = null;
            $session->rejected_by = null;
            $session->rejection_comment = null;
            $session->submitted_at = now();
            $session->save();

            InventorySessionTimeline::log(
                $session,
                DailyInventoryTimelineEventType::RESUBMITTED,
                'Resubmitted by Branch Manager',
                'You resubmitted this daily inventory for review and approval.',
                InventorySessionStatus::REJECTED->value,
                InventorySessionStatus::PENDING->value
            );

            return $session->fresh(['items.item', 'items.purchaseOrderItem.purchaseOrder']);
        });
    }

    /**
     * Approve session (Account Manager). Optionally accept sales per item. Runs discrepancy calculation.
     *
     * @param  array  $sales  Map of inventory_item_id or item_id => sales_quantity
     * @param  array  $recordedWaste  Optional map of inventory_item_id or item_id => recorded_waste
     */
    public function approveSession(string $sessionId, array $sales = [], array $recordedWaste = []): InventorySession
    {
        $session = DB::transaction(function () use ($sessionId, $sales, $recordedWaste) {
            $session = InventorySession::where('id', $sessionId)
                ->where('status', InventorySessionStatus::PENDING)
                ->where(function ($q) {
                    $q->where('assigned_to_type', '!=', 'staff')
                        ->orWhereNotNull('manager_confirmed_at');
                })
                ->with('items')
                ->firstOrFail();

            foreach ($session->items as $item) {
                $qty = $sales[$item->id] ?? $sales[$item->item_id] ?? 0;
                $waste = $recordedWaste[$item->id] ?? $recordedWaste[$item->item_id] ?? null;
                $item->sales_quantity = $qty;
                if ($waste !== null) {
                    $item->recorded_waste = $waste;
                }
                $item->save();
            }

            $session->status = InventorySessionStatus::APPROVED;
            $session->approved_at = now();
            $session->approved_by = auth()->id();
            $session->save();

            $discrepancyService = app(DailyInventoryDiscrepancyService::class);
            $hasDiscrepancy = $discrepancyService->calculateAndStore($session);

            if ($hasDiscrepancy) {
                $session->status = InventorySessionStatus::PENDING_YOUR_CONFIRMATION;
                $session->save();
                InventorySessionTimeline::log(
                    $session,
                    DailyInventoryTimelineEventType::DISCREPANCY_REPORT_SHARED,
                    'Request Received',
                    'Accountant shared discrepancy report.',
                    InventorySessionStatus::APPROVED->value,
                    InventorySessionStatus::PENDING_YOUR_CONFIRMATION->value
                );
            } else {
                $session->status = InventorySessionStatus::COMPLETED;
                $session->save();
                InventorySessionTimeline::log(
                    $session,
                    DailyInventoryTimelineEventType::APPROVED,
                    'Approved by Account Manager',
                    null,
                    InventorySessionStatus::PENDING->value,
                    InventorySessionStatus::COMPLETED->value
                );
            }

            return $session->fresh(['items.item', 'discrepancies']);
        });

        // AFTER commit: re-sync the ASAB operation with the discrepancy figures.
        event(new \Modules\Inventory\Events\InventorySessionSubmittedEvent($session));

        return $session;
    }

    /**
     * Mark discrepancy report as reviewed by Branch Manager (logs timeline).
     */
    public function markDiscrepancyReviewed(string $sessionId, BranchManager $manager): InventorySession
    {
        $session = InventorySession::where('id', $sessionId)
            ->where('branch_id', $manager->branch_id)
            ->where('status', InventorySessionStatus::PENDING_YOUR_CONFIRMATION)
            ->firstOrFail();

        InventorySessionTimeline::log(
            $session,
            DailyInventoryTimelineEventType::DISCREPANCY_REVIEWED,
            'Reviewed',
            'Branch Manager reviewed the report.',
            null,
            null
        );

        return $session->fresh();
    }

    /**
     * Add item to inventory session
     */
    public function addItem(string $sessionId, array $itemData, BranchManager $manager): InventoryItem
    {
        return DB::transaction(function () use ($sessionId, $itemData, $manager) {
            $session = InventorySession::where('id', $sessionId)
                ->where('branch_id', $manager->branch_id)
                ->where('created_by', $manager->id)
                ->whereIn('status', [InventorySessionStatus::DRAFT, InventorySessionStatus::PENDING])
                ->whereNull('submitted_at')
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
     * Update inventory item. Allowed for Branch Manager or Cashier (when session assigned to them).
     */
    public function updateItem(string $itemId, array $data, BranchManager|Cashier $actor): InventoryItem
    {
        return DB::transaction(function () use ($itemId, $data, $actor) {
            $item = InventoryItem::whereHas('inventorySession', function ($query) use ($actor) {
                $query->where('branch_id', $actor->branch_id);
                if ($actor instanceof Cashier) {
                    $query->where('assigned_to_type', 'staff')
                        ->where('assigned_to_id', $actor->id)
                        ->where('status', InventorySessionStatus::PENDING)
                        ->whereNull('submitted_at');
                } else {
                    $query->whereIn('status', [InventorySessionStatus::DRAFT, InventorySessionStatus::PENDING, InventorySessionStatus::REJECTED])
                        ->whereNull('submitted_at')
                        ->where(function ($q) use ($actor) {
                            $q->whereNull('created_by')->orWhere('created_by', $actor->id);
                        });
                }
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
     */
    public function removeItem(string $itemId, BranchManager $manager): bool
    {
        return DB::transaction(function () use ($itemId, $manager) {
            $item = InventoryItem::whereHas('inventorySession', function ($query) use ($manager) {
                $query->where('branch_id', $manager->branch_id)
                    ->whereIn('status', [InventorySessionStatus::DRAFT, InventorySessionStatus::PENDING])
                    ->whereNull('submitted_at');
            })
                ->where('id', $itemId)
                ->firstOrFail();

            return $item->delete();
        });
    }

    /**
     * Last 5 recorded quantities for a product in the branch (for quick suggestions when entering quantity).
     *
     * @return array<int, float>
     */
    public function getLastQuantitiesForProduct(string $branchId, string $itemId): array
    {
        return InventoryItem::query()
            ->where('branch_id', $branchId)
            ->where('item_id', $itemId)
            ->whereHas('inventorySession', function ($q) {
                $q->whereIn('status', [
                    InventorySessionStatus::APPROVED,
                    InventorySessionStatus::COMPLETED,
                ]);
            })
            ->orderByDesc('updated_at')
            ->limit(5)
            ->pluck('quantity_inventory')
            ->map(fn ($q) => (float) $q)
            ->values()
            ->toArray();
    }

    /**
     * Start a daily inventory session. Sets start_time and optionally inventory_date.
     * Allowed for Branch Manager (any session in branch) or Cashier (session assigned to them).
     */
    public function startSession(string $sessionId, BranchManager|Cashier $actor): InventorySession
    {
        $query = InventorySession::where('id', $sessionId)
            ->where('branch_id', $actor->branch_id)
            ->whereIn('status', [InventorySessionStatus::PENDING, InventorySessionStatus::DRAFT]);

        if ($actor instanceof Cashier) {
            $query->where('assigned_to_type', 'staff')->where('assigned_to_id', $actor->id);
        } else {
            $query->where(function ($q) use ($actor) {
                $q->whereNull('created_by')->orWhere('created_by', $actor->id);
            });
        }

        $session = $query->firstOrFail();

        if (! $session->start_time) {
            $session->start_time = now();
        }
        if (! $session->inventory_date) {
            $session->inventory_date = now()->toDateString();
        }
        if (! $session->created_by && $session->status === InventorySessionStatus::PENDING && $actor instanceof BranchManager) {
            $session->created_by = $actor->id;
        }
        $session->save();

        return $session->fresh(['items.item', 'assignedTo', 'createdBy']);
    }

    /**
     * Get session summary. Allowed for Branch Manager or Cashier (when session assigned to them).
     */
    public function getSessionSummary(string $sessionId, BranchManager|Cashier $actor): array
    {
        $query = InventorySession::with([
            'items.item',
            'items.purchaseOrderItem.purchaseOrder',
            'assignedTo',
            'createdBy',
        ])->where('id', $sessionId)->where('branch_id', $actor->branch_id);

        if ($actor instanceof Cashier) {
            $query->where('assigned_to_type', 'staff')->where('assigned_to_id', $actor->id);
        } else {
            $query->where(function ($q) use ($actor) {
                $q->whereNull('created_by')->orWhere('created_by', $actor->id);
            });
        }

        $session = $query->firstOrFail();

        $items = $session->items()->with(['item', 'purchaseOrderItem.purchaseOrder'])->get();
        $totalItems = $items->count();
        $completedCount = $items->filter(fn ($i) => (float) $i->quantity_inventory > 0)->count();

        $performedBy = ($session->assigned_to_type === 'staff' && $session->assigned_to_id && $session->assignedTo)
            ? ['id' => $session->assigned_to_id, 'name' => $session->assignedTo->name]
            : ['id' => $session->created_by, 'name' => $session->createdBy?->name];

        return [
            'session_summary' => [
                'inventory_date' => $session->inventory_date?->format('Y-m-d'),
                'start_time' => $session->start_time?->format('Y-m-d H:i:s'),
                'end_time' => $session->end_time?->format('Y-m-d H:i:s'),
                'time_taken' => $session->time_taken_formatted,
                'performed_by' => $performedBy,
                'completed_products' => $totalItems > 0 ? "{$completedCount}/{$totalItems} (".round($completedCount / $totalItems * 100).'%)' : '0/0 (0%)',
                'completed_count' => $completedCount,
                'total_count' => $totalItems,
                'status' => $session->status->value,
                'status_label' => $session->status_label,
            ],
            'all_inventoried_products' => $items->map(function ($item) {
                return [
                    'item_name' => $item->item_name ?? $item->item?->name ?? '',
                    'recorded_quantity' => (float) $item->quantity_inventory,
                    'quantity' => (float) $item->quantity_inventory,
                    'unit' => $item->item?->unit ?? 'kg',
                    'notes' => $item->notes,
                ];
            })->toArray(),
        ];
    }
}
