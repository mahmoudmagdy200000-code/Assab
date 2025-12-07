<?php

namespace Modules\Purchase\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Modules\Purchase\Enums\ReturnRequiredAction;
use Modules\Purchase\Enums\ReturnStatus;
use Modules\Purchase\Models\OrderDocument;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Models\ReturnOrder;
use Modules\Purchase\Models\ReturnOrderItem;
use Modules\Purchase\Enums\DocumentType;

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
        return ReturnOrder::with(['purchaseOrder', 'supplier', 'items'])
            ->byBranch($branchId)
            ->inProgress()
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    /**
     * Get draft returns
     */
    public function getDraftReturns(string $branchId, int $perPage = 15): LengthAwarePaginator
    {
        return ReturnOrder::with(['purchaseOrder', 'supplier', 'items'])
            ->byBranch($branchId)
            ->draft()
            ->orderBy('updated_at', 'desc')
            ->paginate($perPage);
    }

    /**
     * Get completed returns
     */
    public function getCompletedReturns(string $branchId, int $perPage = 15): LengthAwarePaginator
    {
        return ReturnOrder::with(['purchaseOrder', 'supplier', 'items'])
            ->byBranch($branchId)
            ->completed()
            ->orderBy('closed_at', 'desc')
            ->paginate($perPage);
    }

    /**
     * Create return order
     */
    public function createReturn(PurchaseOrder $order, array $data): ReturnOrder
    {
        return DB::transaction(function () use ($order, $data) {
            $returnOrder = ReturnOrder::create([
                'purchase_order_id' => $order->id,
                'supplier_id' => $order->supplier_id,
                'branch_id' => $order->branch_id,
                'created_by' => $data['created_by'],
                'return_date' => $data['return_date'] ?? now(),
                'status' => ReturnStatus::DRAFT,
                'required_action' => $data['required_action'],
                'additional_notes' => $data['additional_notes'] ?? null,
            ]);

            // Add return items
            foreach ($data['items'] as $itemData) {
                $this->addReturnItem($returnOrder, $itemData);
            }

            $returnOrder->calculateTotalReturnAmount();

            $this->timelineService->logReturnCreated($returnOrder);

            return $returnOrder->fresh(['items', 'purchaseOrder', 'supplier']);
        });
    }

    /**
     * Add item to return order
     */
    public function addReturnItem(ReturnOrder $returnOrder, array $data): ReturnOrderItem
    {
        $returnAmount = ($data['return_quantity'] ?? 0) * ($data['unit_price'] ?? 0);
        
        return ReturnOrderItem::create([
            'return_order_id' => $returnOrder->id,
            'purchase_order_item_id' => $data['purchase_order_item_id'] ?? null,
            'item_name' => $data['item_name'],
            'item_logo' => $data['item_logo'] ?? null,
            'return_quantity' => $data['return_quantity'],
            'unit_of_measurement' => $data['unit'] ?? 'kg',
            'quality_reason' => $data['quality_reason'],
            'unit_price' => $data['unit_price'] ?? 0,
            'return_amount' => $returnAmount,
            'files' => $data['files'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);
    }

    /**
     * Update return order
     */
    public function updateReturn(ReturnOrder $returnOrder, array $data): ReturnOrder
    {
        return DB::transaction(function () use ($returnOrder, $data) {
            $returnOrder->update([
                'required_action' => $data['required_action'] ?? $returnOrder->required_action,
                'additional_notes' => $data['additional_notes'] ?? $returnOrder->additional_notes,
            ]);

            // Update items if provided
            if (!empty($data['items'])) {
                foreach ($data['items'] as $itemData) {
                    if (!empty($itemData['id'])) {
                        $item = ReturnOrderItem::find($itemData['id']);
                        if ($item) {
                            $item->update([
                                'return_quantity' => $itemData['return_quantity'] ?? $item->return_quantity,
                                'quality_reason' => $itemData['quality_reason'] ?? $item->quality_reason,
                                'files' => $itemData['files'] ?? $item->files,
                                'notes' => $itemData['notes'] ?? $item->notes,
                            ]);
                            $item->calculateReturnAmount();
                        }
                    }
                }
            }

            $returnOrder->calculateTotalReturnAmount();

            return $returnOrder->fresh(['items']);
        });
    }

    /**
     * Submit return order
     */
    public function submitReturn(ReturnOrder $returnOrder): bool
    {
        if (!$returnOrder->submit()) {
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
            $uploadedFiles[] = $document->file_path;
        }
        
        $existingFiles = $item->files ?? [];
        $item->update([
            'files' => array_merge($existingFiles, $uploadedFiles),
        ]);
    }

    /**
     * Save return as draft
     */
    public function saveDraft(PurchaseOrder $order, array $data): ReturnOrder
    {
        $data['status'] = ReturnStatus::DRAFT;
        return $this->createReturn($order, $data);
    }

    /**
     * Delete draft
     */
    public function deleteDraft(ReturnOrder $returnOrder): bool
    {
        if (!$returnOrder->is_draft) {
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

