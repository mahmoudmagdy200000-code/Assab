<?php

namespace Modules\Purchase\Services;

use Modules\Purchase\Models\PurchaseReturn;
use Modules\Purchase\Models\PurchaseReturnItem;
use Modules\Purchase\Models\PurchaseReturnTimeline;
use Modules\Purchase\Repositories\PurchaseReturnRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Auth\User as Authenticatable;

class PurchaseReturnService
{
    public function __construct(
        private PurchaseReturnRepository $purchaseReturnRepository
    ) {}

    public function getReturnsList(array $filters)
    {
        return $this->purchaseReturnRepository->getReturns($filters);
    }

    public function createReturn(array $data, Authenticatable $user): PurchaseReturn
    {
        return DB::transaction(function () use ($data, $user) {
            $returnData = [
                'purchase_order_id' => $data['purchase_order_id'] ?? null,
                'goods_receipt_id' => $data['goods_receipt_id'] ?? null,
                'branch_id' => $user->branch_id,
                'supplier_id' => $data['supplier_id'],
                'created_by_id' => $user->id,
                'created_by_type' => get_class($user),
                'return_date' => $data['return_date'] ?? now(),
                'required_action' => $data['required_action'],
                'status' => $data['status'] ?? 'pending',
                'additional_notes' => $data['additional_notes'] ?? null,
            ];

            $return = $this->purchaseReturnRepository->create($returnData);
            $return->return_number = $return->generateReturnNumber();
            $return->save();

            $totalAmount = 0;
            if (!empty($data['items'])) {
                foreach ($data['items'] as $itemData) {
                    $returnItem = new PurchaseReturnItem([
                        'item_id' => $itemData['item_id'],
                        'item_name' => $itemData['item_name'],
                        'return_quantity' => $itemData['return_quantity'],
                        'unit' => $itemData['unit'],
                        'quality_reason' => $itemData['quality_reason'],
                        'return_amount' => $itemData['return_amount'],
                        'files' => $itemData['files'] ?? [],
                        'notes' => $itemData['notes'] ?? null,
                    ]);

                    if (!empty($itemData['uploaded_files'])) {
                        $uploadedFiles = [];
                        foreach ($itemData['uploaded_files'] as $file) {
                            $path = $file->store('return-items', 'public');
                            $uploadedFiles[] = $path;
                        }
                        $returnItem->files = $uploadedFiles;
                    }

                    $return->items()->save($returnItem);
                    $totalAmount += $itemData['return_amount'];
                }
            }

            $return->total_return_amount = $totalAmount;
            $return->save();

            $this->createTimelineEntry(
                $return,
                $user,
                'submitted',
                'Return request submitted by branch manager'
            );

            return $return->fresh(['items', 'timeline']);
        });
    }

    public function getReturnDetails($returnId)
    {
        return $this->purchaseReturnRepository->findWithRelations($returnId, [
            'purchaseOrder',
            'goodsReceipt',
            'branch',
            'supplier',
            'createdBy',
            'items.item',
            'timeline.user',
        ]);
    }

    public function acceptRejection($returnId, Authenticatable $user): PurchaseReturn
    {
        return DB::transaction(function () use ($returnId, $user) {
            $return = $this->purchaseReturnRepository->findOrFail($returnId);

            if ($return->status !== 'rejected') {
                throw new \Exception('Only rejected returns can be accepted');
            }

            $return->update(['status' => 'closed']);

            $this->createTimelineEntry(
                $return,
                $user,
                'approved',
                'Rejection accepted by branch manager'
            );

            return $return->fresh(['timeline']);
        });
    }

    public function escalateRejection($returnId, string $reason, Authenticatable $user): PurchaseReturn
    {
        return DB::transaction(function () use ($returnId, $reason, $user) {
            $return = $this->purchaseReturnRepository->findOrFail($returnId);

            if ($return->status !== 'rejected') {
                throw new \Exception('Only rejected returns can be escalated');
            }

            $return->update([
                'escalated_to_brand_owner' => true,
                'escalation_reason' => $reason,
                'status' => 'pending',
            ]);

            $this->createTimelineEntry(
                $return,
                $user,
                'escalated',
                'Return escalated to brand owner',
                ['reason' => $reason]
            );

            return $return->fresh(['timeline']);
        });
    }

    public function getReturnTimeline($returnId)
    {
        $return = $this->purchaseReturnRepository->findOrFail($returnId);
        return $return->timeline()->with('user')->orderBy('created_at', 'asc')->get();
    }

    private function createTimelineEntry(
        PurchaseReturn $return,
        Authenticatable $user,
        string $action,
        string $description,
        array $metadata = []
    ): void {
        PurchaseReturnTimeline::create([
            'purchase_return_id' => $return->id,
            'user_id' => $user->id,
            'user_type' => get_class($user),
            'action' => $action,
            'description' => $description,
            'metadata' => $metadata,
        ]);
    }
}
