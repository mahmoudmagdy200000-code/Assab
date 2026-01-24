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
     *
     * @param  bool  $asInProgress  When true, creates as PENDING (In Progress) immediately; otherwise DRAFT.
     */
    public function createReturn(PurchaseOrder $order, array $data, $request = null, bool $asInProgress = false): ReturnOrder
    {
        return DB::transaction(function () use ($order, $data, $request, $asInProgress) {
            $returnOrder = ReturnOrder::create([
                'purchase_order_id' => $order->id,
                'supplier_id' => $order->supplier_id,
                'branch_id' => $order->branch_id,
                'created_by' => $data['created_by'],
                'return_date' => $data['return_date'] ?? now(),
                'status' => $asInProgress ? ReturnStatus::PENDING : ReturnStatus::DRAFT,
                'required_action' => $data['required_action'],
                'additional_notes' => $data['additional_notes'] ?? null,
                'submitted_at' => $asInProgress ? now() : null,
            ]);

            foreach ($data['items'] as $index => $itemData) {
                $this->addReturnItem($returnOrder, $itemData, $request, $index);
            }

            $returnOrder->calculateTotalReturnAmount();

            $this->timelineService->logReturnCreated($returnOrder);
            if ($asInProgress) {
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
        // Get purchase order item details
        $purchaseOrderItem = \Modules\Purchase\Models\PurchaseOrderItem::find($data['purchase_order_item_id']);
        
        if (!$purchaseOrderItem) {
            throw new \InvalidArgumentException("Purchase order item not found: {$data['purchase_order_item_id']}");
        }
        
        // Verify the item belongs to the same purchase order
        if ($purchaseOrderItem->purchase_order_id !== $returnOrder->purchase_order_id) {
            throw new \InvalidArgumentException("Purchase order item does not belong to the specified purchase order");
        }
        
        // Calculate return amount using unit price from purchase order item
        $returnAmount = ($data['return_quantity'] ?? 0) * ($purchaseOrderItem->unit_price ?? 0);
        
        // Handle file uploads if request is provided
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
                    $uploadedFiles[] = $document->file_path;
                }
            } elseif ($files) {
                $document = OrderDocument::upload(
                    $returnOrder,
                    $files,
                    DocumentType::PHOTO,
                    "Return evidence for {$purchaseOrderItem->item_name}"
                );
                $uploadedFiles[] = $document->file_path;
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
            'files' => !empty($uploadedFiles) ? $uploadedFiles : null,
            'notes' => null,
        ]);
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
            if (!empty($data['items'])) {
                foreach ($data['items'] as $index => $itemData) {
                    if (!empty($itemData['id'])) {
                        $item = ReturnOrderItem::find($itemData['id']);
                        if ($item && $item->return_order_id === $returnOrder->id) {
                            // Handle file uploads
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
                                        $uploadedFiles[] = $document->file_path;
                                    }
                                } elseif ($files) {
                                    $document = OrderDocument::upload(
                                        $returnOrder,
                                        $files,
                                        DocumentType::PHOTO,
                                        "Return evidence for {$item->item_name}"
                                    );
                                    $uploadedFiles[] = $document->file_path;
                                }
                            }
                            
                            // Merge files
                            $existingFiles = $item->files ?? [];
                            $newFiles = $itemData['files'] ?? [];
                            $allFiles = array_unique(array_merge($existingFiles, $newFiles, $uploadedFiles));
                            
                            // Handle file deletions if specified
                            if (isset($itemData['deleted_files']) && is_array($itemData['deleted_files'])) {
                                $allFiles = array_diff($allFiles, $itemData['deleted_files']);
                            }
                            
                            $item->update([
                                'return_quantity' => $itemData['return_quantity'] ?? $item->return_quantity,
                                'quality_reason' => $itemData['quality_reason'] ?? $item->quality_reason,
                                'files' => !empty($allFiles) ? array_values($allFiles) : null,
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

