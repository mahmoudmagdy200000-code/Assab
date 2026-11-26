<?php

namespace Modules\Purchase\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Modules\Admin\Exceptions\AsabException;
use Modules\Branch\Models\Branch;
use Modules\Inventory\Models\InventoryStockMovement;
use Modules\Purchase\Enums\DocumentType;
use Modules\Purchase\Enums\ReturnStatus;
use Modules\Purchase\Models\BranchInventory;
use Modules\Purchase\Models\GoodsReceiptItem;
use Modules\Purchase\Models\OrderDocument;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Models\PurchaseOrderItem;
use Modules\Purchase\Models\ReturnOrder;
use Modules\Purchase\Models\ReturnOrderItem;
use Modules\Purchase\Support\PurchaseFileHelper;

class ReturnManagementService
{
    public function __construct(
        private readonly TimelineService $timelineService
    ) {}

    /**
     * Get returns in progress
     */
    public function getInProgressReturns(string $branchId, int $perPage = 15): LengthAwarePaginator
    {
        return ReturnOrder::byBranch($branchId)
            ->inProgress()
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    /**
     * Get draft returns
     */
    public function getDraftReturns(string $branchId, int $perPage = 15): LengthAwarePaginator
    {
        return ReturnOrder::byBranch($branchId)
            ->draft()
            ->orderBy('updated_at', 'desc')
            ->paginate($perPage);
    }

    /**
     * Get completed returns
     */
    public function getCompletedReturns(string $branchId, int $perPage = 15): LengthAwarePaginator
    {
        return ReturnOrder::byBranch($branchId)
            ->completed()
            ->orderBy('closed_at', 'desc')
            ->paginate($perPage);
    }

    /**
     * Brand owner: returns currently escalated (awaiting decision) within visible branches.
     *
     * @param  string[]  $branchIds
     */
    public function getBrandOwnerInProgressReturns(array $branchIds, int $perPage = 15): LengthAwarePaginator
    {
        return ReturnOrder::brandOwnerInProgress()
            ->whereIn('branch_id', $branchIds)
            ->orderBy('escalated_at', 'desc')
            ->paginate($perPage);
    }

    /**
     * Brand owner: returns whose escalation has been resolved or rejected within visible branches.
     *
     * @param  string[]  $branchIds
     */
    public function getBrandOwnerCompletedReturns(array $branchIds, int $perPage = 15): LengthAwarePaginator
    {
        return ReturnOrder::brandOwnerCompleted()
            ->whereIn('branch_id', $branchIds)
            ->orderBy('resolved_at', 'desc')
            ->paginate($perPage);
    }

    /**
     * Create return order. Always creates as PENDING (In Progress) so it appears in In Progress list.
     */
    public function createReturn(PurchaseOrder $order, array $data, $request = null): ReturnOrder
    {
        return DB::transaction(function () use ($order, $data, $request) {
            $isDraft = isset($data['status']) && $data['status'] === ReturnStatus::DRAFT;

            $returnOrder = ReturnOrder::create([
                'purchase_order_id' => $order->id,
                'supplier_id' => $order->supplier_id,
                'branch_id' => $order->branch_id,
                'created_by' => $data['created_by'],
                'return_date' => $data['return_date'] ?? now(),
                'status' => $isDraft ? ReturnStatus::DRAFT : ReturnStatus::PENDING,
                'required_action' => $data['required_action'],
                'additional_notes' => $data['additional_notes'] ?? null,
                'submitted_at' => $isDraft ? null : now(),
            ]);

            foreach ($data['items'] as $index => $itemData) {
                $this->addReturnItem($returnOrder, $itemData, $request, $index);
            }

            $returnOrder->calculateTotalReturnAmount();

            $this->timelineService->logReturnCreated($returnOrder);
            if (! $isDraft) {
                $this->timelineService->logReturnSubmitted($returnOrder);
            }

            return $returnOrder->fresh(['items', 'purchaseOrder', 'supplier']);
        });
    }

    /**
     * Add item to return order
     */
    public function addReturnItem(ReturnOrder $returnOrder, array $data, $request = null, int $itemIndex = 0): ReturnOrderItem
    {
        // T3.4 / DEC-02 / S3-CODEX-002: Enforce cumulative net received cap including same-return lines
        $returnQty = round((float) ($data['return_quantity'] ?? 0), 3);
        $purchaseOrderItem = $this->assertWithinNetReceivedCap(
            (string) $data['purchase_order_item_id'],
            $returnQty,
            expectedOrderId: (string) $returnOrder->purchase_order_id
        );

        // Calculate return amount using unit price from purchase order item
        $returnAmount = ($data['return_quantity'] ?? 0) * ($purchaseOrderItem->unit_price ?? 0);

        $uploadedFiles = [];
        if ($request && $request->hasFile("items.{$itemIndex}.files")) {
            $files = $request->file("items.{$itemIndex}.files");
            if (is_array($files)) {
                foreach ($files as $file) {
                    $document = OrderDocument::upload(
                        $returnOrder,
                        $file,
                        DocumentType::PHOTO,
                        "Return evidence for {$purchaseOrderItem->item_name}"
                    );
                    $uploadedFiles[] = PurchaseFileHelper::toStorable($document);
                }
            } elseif ($files) {
                $document = OrderDocument::upload(
                    $returnOrder,
                    $files,
                    DocumentType::PHOTO,
                    "Return evidence for {$purchaseOrderItem->item_name}"
                );
                $uploadedFiles[] = PurchaseFileHelper::toStorable($document);
            }
        }

        return ReturnOrderItem::create([
            'return_order_id' => $returnOrder->id,
            'purchase_order_item_id' => $purchaseOrderItem->id,
            'item_name' => $purchaseOrderItem->item_name,
            'item_logo' => $purchaseOrderItem->item_logo,
            'return_quantity' => $data['return_quantity'],
            'unit_of_measurement' => $purchaseOrderItem->unit_of_measurement ?? 'kg',
            'quality_reason' => $data['quality_reason'],
            'unit_price' => $purchaseOrderItem->unit_price ?? 0,
            'return_amount' => $returnAmount,
            'files' => $uploadedFiles ?: null,
            'notes' => null,
        ]);
    }

    /**
     * T3.4 / DEC-02 / S3-CODEX-002: Enforce cumulative net received cap across all active
     * return lines (including same-return lines) with MySQL REPEATABLE-READ locking.
     */
    private function assertWithinNetReceivedCap(
        string $purchaseOrderItemId,
        float $candidateQty,
        ?string $expectedOrderId = null,
        ?string $excludeReturnItemId = null,
        ?string $excludeReturnOrderId = null
    ): \Modules\Purchase\Models\PurchaseOrderItem {
        $purchaseOrderItem = \Modules\Purchase\Models\PurchaseOrderItem::whereKey($purchaseOrderItemId)
            ->lockForUpdate()
            ->first();

        if (! $purchaseOrderItem) {
            throw new \InvalidArgumentException("Purchase order item not found: {$purchaseOrderItemId}");
        }

        if ($expectedOrderId !== null && $purchaseOrderItem->purchase_order_id !== $expectedOrderId) {
            throw new \InvalidArgumentException('Purchase order item does not belong to the specified purchase order');
        }

        $totalReceivedPosted = max(
            round((float) ($purchaseOrderItem->quantity_received ?? 0), 3),
            round((float) GoodsReceiptItem::where('purchase_order_item_id', $purchaseOrderItem->id)
                ->lockForUpdate()
                ->get(['quantity_available_posted'])
                ->sum('quantity_available_posted'), 3)
        );

        $alreadyReturnedQuery = ReturnOrderItem::query()
            ->join('return_orders', 'return_order_items.return_order_id', '=', 'return_orders.id')
            ->where('return_order_items.purchase_order_item_id', $purchaseOrderItem->id)
            ->whereNull('return_orders.deleted_at')
            ->whereNotIn('return_orders.status', [ReturnStatus::REJECTED->value, 'rejected']);

        if ($excludeReturnItemId !== null) {
            $alreadyReturnedQuery->where('return_order_items.id', '!=', $excludeReturnItemId);
        }
        if ($excludeReturnOrderId !== null) {
            $alreadyReturnedQuery->where('return_order_items.return_order_id', '!=', $excludeReturnOrderId);
        }

        $alreadyReturned = round((float) $alreadyReturnedQuery
            ->lockForUpdate()
            ->get(['return_order_items.return_quantity'])
            ->sum('return_quantity'), 3);

        $maxReturnable = max(0.0, round($totalReceivedPosted - $alreadyReturned, 3));
        if ($candidateQty > $maxReturnable + 0.0005) {
            throw new AsabException(
                'RETURN_EXCEEDS_NET_RECEIVED',
                "Return quantity ({$candidateQty}) exceeds net received quantity ({$maxReturnable}).",
                status: 422
            );
        }

        return $purchaseOrderItem;
    }

    /**
     * T3.4 / DEC-02: Confirm physical handover of return items to supplier.
     * Deducts available inventory once (Idempotent) and records stock movements.
     */
    public function confirmHandover(string $returnId, ?string $actorId = null): ReturnOrder
    {
        return DB::transaction(function () use ($returnId, $actorId) {
            $return = ReturnOrder::with(['purchaseOrder.branch'])->whereKey($returnId)->lockForUpdate()->firstOrFail();

            // Idempotent: already handed over
            if ($return->handed_over_at !== null) {
                return $return;
            }

            $items = $return->items()->with('purchaseOrderItem')->lockForUpdate()->get();

            // S3-CODEX-002: Verify cumulative return quantity per PO item before deducting any stock
            foreach ($items->groupBy('purchase_order_item_id') as $poItemId => $groupedItems) {
                if (! $poItemId) {
                    continue;
                }
                $sumQty = round((float) $groupedItems->sum('return_quantity'), 3);
                $this->assertWithinNetReceivedCap(
                    (string) $poItemId,
                    $sumQty,
                    excludeReturnOrderId: (string) $return->id
                );
            }

            $order = $return->purchaseOrder;
            $companyId = (string) ($order?->branch?->asab_company_id ?? Branch::whereKey($return->branch_id)->value('asab_company_id') ?? '');
            $now = now();

            foreach ($items as $returnItem) {
                $returnItem->loadMissing('purchaseOrderItem');
                $itemId = $returnItem->purchaseOrderItem?->item_id;
                if (! $itemId) {
                    continue;
                }

                $qty = round((float) $returnItem->return_quantity, 3);
                if ($qty <= 0) {
                    continue;
                }

                $idemKey = "return_handover:{$return->id}:{$returnItem->id}";
                if (InventoryStockMovement::where('idempotency_key', $idemKey)->lockForUpdate()->exists()) {
                    continue;
                }

                $unitCostHalalas = (int) round(((float) ($returnItem->unit_price ?? 0)) * 100);
                $totalCostHalalas = (int) round(((float) ($returnItem->return_amount ?? ($qty * (float) $returnItem->unit_price))) * 100);

                // Deduct from BranchInventory
                $branchInv = BranchInventory::where('branch_id', $return->branch_id)
                    ->where('item_id', $itemId)
                    ->lockForUpdate()
                    ->first();
                if ($branchInv) {
                    $branchInv->decrement('available_quantity', $qty);
                }

                // Deduct from BranchItem pivot if present
                $branchItem = DB::table('branch_item')
                    ->where('branch_id', $return->branch_id)
                    ->where('item_id', $itemId)
                    ->first();
                if ($branchItem) {
                    DB::table('branch_item')
                        ->where('branch_id', $return->branch_id)
                        ->where('item_id', $itemId)
                        ->decrement('quantity', $qty);
                }

                // Record InventoryStockMovement
                InventoryStockMovement::create([
                    'company_id' => $companyId,
                    'branch_id' => (string) $return->branch_id,
                    'item_id' => $itemId,
                    'idempotency_key' => $idemKey,
                    'movement_type' => 'return',
                    'qty_delta' => -1 * $qty,
                    'unit_cost_halalas' => $unitCostHalalas,
                    'total_cost_halalas' => -1 * $totalCostHalalas,
                    'source_type' => 'purchase_return_handover',
                    'source_id' => $return->id,
                    'revision' => 1,
                    'cost_source' => 'RETURN_HANDOVER',
                    'posted_at' => $now,
                    'created_by_id' => $actorId,
                    'notes' => 'تسليم مرتجع مشتريات فعلي — '.$return->return_number,
                    'meta' => [
                        'return_order_id' => $return->id,
                        'return_order_item_id' => $returnItem->id,
                        'purchase_order_id' => $return->purchase_order_id,
                        'purchase_order_item_id' => $returnItem->purchase_order_item_id,
                        'return_quantity' => $qty,
                    ],
                ]);
            }

            $return->update([
                'handed_over_at' => $now,
                'handed_over_by' => $actorId,
            ]);

            return $return->fresh(['items.purchaseOrderItem', 'purchaseOrder', 'supplier']);
        });
    }

    /**
     * Update return order
     */
    public function updateReturn(ReturnOrder $returnOrder, array $data, $request = null): ReturnOrder
    {
        return DB::transaction(function () use ($returnOrder, $data, $request) {
            $returnOrder->update([
                'required_action' => $data['required_action'] ?? $returnOrder->required_action,
                'additional_notes' => $data['additional_notes'] ?? $returnOrder->additional_notes,
            ]);

            // Update items if provided
            if (! empty($data['items'])) {
                foreach ($data['items'] as $index => $itemData) {
                    if (! empty($itemData['id'])) {
                        $item = ReturnOrderItem::find($itemData['id']);
                        if ($item && $item->return_order_id === $returnOrder->id) {
                            $uploadedFiles = [];
                            if ($request && $request->hasFile("items.{$index}.files")) {
                                $files = $request->file("items.{$index}.files");
                                if (is_array($files)) {
                                    foreach ($files as $file) {
                                        $document = OrderDocument::upload(
                                            $returnOrder,
                                            $file,
                                            DocumentType::PHOTO,
                                            "Return evidence for {$item->item_name}"
                                        );
                                        $uploadedFiles[] = PurchaseFileHelper::toStorable($document);
                                    }
                                } elseif ($files) {
                                    $document = OrderDocument::upload(
                                        $returnOrder,
                                        $files,
                                        DocumentType::PHOTO,
                                        "Return evidence for {$item->item_name}"
                                    );
                                    $uploadedFiles[] = PurchaseFileHelper::toStorable($document);
                                }
                            }

                            $existingFiles = $item->files ?? [];
                            $allFiles = array_merge(
                                is_array($existingFiles) ? $existingFiles : [],
                                $uploadedFiles
                            );

                            $deletedIds = $itemData['deleted_files'] ?? [];
                            if (is_array($deletedIds) && ! empty($deletedIds)) {
                                $allFiles = array_values(array_filter($allFiles, function ($f) use ($deletedIds) {
                                    $id = is_array($f) ? ($f['id'] ?? null) : null;

                                    return ! $id || ! in_array($id, $deletedIds, true);
                                }));
                                foreach ($deletedIds as $docId) {
                                    OrderDocument::find($docId)?->delete();
                                }
                            }

                            $newQty = round((float) ($itemData['return_quantity'] ?? $item->return_quantity), 3);
                            $this->assertWithinNetReceivedCap(
                                (string) $item->purchase_order_item_id,
                                $newQty,
                                excludeReturnItemId: (string) $item->id
                            );

                            $item->update([
                                'return_quantity' => $itemData['return_quantity'] ?? $item->return_quantity,
                                'quality_reason' => $itemData['quality_reason'] ?? $item->quality_reason,
                                'files' => $allFiles ?: null,
                            ]);
                            $item->calculateReturnAmount();
                        }
                    } else {
                        // New item - add it
                        $this->addReturnItem($returnOrder, $itemData, $request, $index);
                    }
                }
            }

            $returnOrder->calculateTotalReturnAmount();

            return $returnOrder->fresh(['items', 'purchaseOrder', 'supplier']);
        });
    }

    /**
     * Submit return order
     */
    public function submitReturn(ReturnOrder $returnOrder): bool
    {
        if (! $returnOrder->submit()) {
            return false;
        }

        $this->timelineService->logReturnSubmitted($returnOrder);

        return true;
    }

    /**
     * Supplier approves return
     */
    public function approveReturn(
        ReturnOrder $returnOrder,
        string $respondedBy,
        ?float $refundAmount = null,
        ?string $refundMethod = null,
        ?array $files = null,
        ?string $notes = null
    ): void {
        $returnOrder->approve($respondedBy, $refundAmount, $refundMethod, $files, $notes);
        $this->timelineService->logReturnApproved($returnOrder);
    }

    /**
     * Supplier rejects return
     */
    public function rejectReturn(ReturnOrder $returnOrder, string $respondedBy, string $reason): void
    {
        $returnOrder->reject($respondedBy, $reason);
        $this->timelineService->logReturnRejected($returnOrder, $reason);
    }

    /**
     * Accept rejection
     */
    public function acceptRejection(ReturnOrder $returnOrder): void
    {
        $returnOrder->acceptRejection();
        $this->timelineService->logRejectionAccepted($returnOrder);
    }

    /**
     * Escalate return
     */
    public function escalateReturn(ReturnOrder $returnOrder, string $reason, string $escalatedTo): void
    {
        $returnOrder->escalate($reason, $escalatedTo);
        $this->timelineService->logReturnEscalated($returnOrder, $reason);
    }

    /**
     * Resolve return (by Brand Owner)
     */
    public function resolveReturn(ReturnOrder $returnOrder, string $resolvedBy, string $resolutionType, ?string $notes = null): void
    {
        $returnOrder->resolve($resolvedBy, $resolutionType, $notes);
        $this->timelineService->logReturnResolved($returnOrder, $resolutionType);
    }

    /**
     * Brand owner approves escalation. Status -> ESCALATED_RESOLVED.
     */
    public function approveEscalation(ReturnOrder $returnOrder, string $brandOwnerId, ?string $notes = null): ReturnOrder
    {
        if ($returnOrder->status !== ReturnStatus::ESCALATED) {
            throw new \DomainException('Return is not in escalated state');
        }

        return DB::transaction(function () use ($returnOrder, $brandOwnerId, $notes) {
            $returnOrder->approveEscalation($brandOwnerId, $notes);
            $this->timelineService->logEscalationApproved($returnOrder, $notes);

            return $returnOrder->fresh();
        });
    }

    /**
     * Brand owner rejects escalation. Status -> ESCALATED_REJECTED.
     */
    public function rejectEscalation(ReturnOrder $returnOrder, string $brandOwnerId, string $reason): ReturnOrder
    {
        if ($returnOrder->status !== ReturnStatus::ESCALATED) {
            throw new \DomainException('Return is not in escalated state');
        }

        return DB::transaction(function () use ($returnOrder, $brandOwnerId, $reason) {
            $returnOrder->rejectEscalation($brandOwnerId, $reason);
            $this->timelineService->logEscalationRejected($returnOrder, $reason);

            return $returnOrder->fresh();
        });
    }

    /**
     * Upload files for return item
     */
    public function uploadFiles(ReturnOrderItem $item, array $files): void
    {
        $uploadedFiles = [];
        foreach ($files as $file) {
            $document = OrderDocument::upload(
                $item->returnOrder,
                $file,
                DocumentType::PHOTO,
                "Return evidence for {$item->item_name}"
            );
            $uploadedFiles[] = PurchaseFileHelper::toStorable($document);
        }
        $existingFiles = $item->files ?? [];
        $allFiles = array_merge(is_array($existingFiles) ? $existingFiles : [], $uploadedFiles);
        $item->update(['files' => $allFiles ?: null]);
    }

    /**
     * Save return as draft
     */
    public function saveDraft(PurchaseOrder $order, array $data, $request = null): ReturnOrder
    {
        $data['status'] = ReturnStatus::DRAFT;

        return $this->createReturn($order, $data, $request);
    }

    /**
     * Delete draft
     */
    public function deleteDraft(ReturnOrder $returnOrder): bool
    {
        if (! $returnOrder->is_draft) {
            return false;
        }

        return $returnOrder->delete();
    }

    /**
     * Get return details
     */
    public function getReturnDetails(string $returnId): ?ReturnOrder
    {
        return ReturnOrder::with([
            'purchaseOrder.supplier',
            'supplier',
            'branch',
            'createdBy',
            'respondedBy',
            'items',
            'timelines',
            'documents',
        ])->find($returnId);
    }

    /**
     * Get return timeline
     */
    public function getReturnTimeline(string $returnId): \Illuminate\Database\Eloquent\Collection
    {
        $returnOrder = ReturnOrder::find($returnId);

        return $returnOrder?->timelines ?? collect();
    }
}
