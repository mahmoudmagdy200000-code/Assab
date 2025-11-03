<?php

namespace Modules\Purchase\Services;

use Modules\Purchase\Models\GoodsReceipt;
use Modules\Purchase\Models\GoodsReceiptItem;
use Modules\Purchase\Models\GoodsReceiptVariance;
use Modules\Purchase\Repositories\GoodsReceiptRepository;
use Modules\Purchase\Repositories\PurchaseOrderRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Auth\User as Authenticatable;

class GoodsReceiptService
{
    public function __construct(
        private GoodsReceiptRepository $goodsReceiptRepository,
        private PurchaseOrderRepository $purchaseOrderRepository
    ) {}

    public function getReceiptsList(array $filters)
    {
        return $this->goodsReceiptRepository->getReceipts($filters);
    }

    public function createReceipt(array $data, Authenticatable $user): GoodsReceipt
    {
        return DB::transaction(function () use ($data, $user) {
            $receiptData = [
                'purchase_order_id' => $data['purchase_order_id'] ?? null,
                'branch_id' => $user->branch_id,
                'received_by_id' => $user->id,
                'received_by_type' => get_class($user),
                'driver_name' => $data['driver_name'],
                'driver_contact' => $data['driver_contact'],
                'vehicle_number' => $data['vehicle_number'],
                'arrival_time' => $data['arrival_time'],
                'document_type' => $data['document_type'],
                'invoice_number' => $data['invoice_number'] ?? null,
                'invoice_date' => $data['invoice_date'] ?? null,
                'supplier_id' => $data['supplier_id'] ?? null,
                'amount_before_tax' => $data['amount_before_tax'] ?? 0,
                'payment_terms' => $data['payment_terms'] ?? 'Net 30 days',
                'status' => 'completed',
                'notes' => $data['notes'] ?? null,
            ];

            $receiptData['vat_amount'] = $receiptData['amount_before_tax'] * 0.15;
            $receiptData['total_amount'] = $receiptData['amount_before_tax'] + $receiptData['vat_amount'];

            if (!empty($data['invoice_date'])) {
                $receiptData['due_date'] = \Carbon\Carbon::parse($data['invoice_date'])->addDays(30);
            }

            $receipt = $this->goodsReceiptRepository->create($receiptData);
            $receipt->receipt_number = $receipt->generateReceiptNumber();

            if (!empty($data['invoice_file'])) {
                $path = $data['invoice_file']->store('invoices', 'public');
                $receipt->invoice_file = $path;
            }

            $receipt->save();

            $varianceCount = 0;
            if (!empty($data['items'])) {
                foreach ($data['items'] as $itemData) {
                    $receiptItem = new GoodsReceiptItem([
                        'purchase_order_item_id' => $itemData['purchase_order_item_id'] ?? null,
                        'item_id' => $itemData['item_id'],
                        'item_name' => $itemData['item_name'],
                        'quantity_ordered' => $itemData['quantity_ordered'] ?? $itemData['quantity_received'],
                        'quantity_received' => $itemData['quantity_received'],
                        'unit' => $itemData['unit'],
                        'quality' => $itemData['quality'] ?? 'normal',
                        'temperature' => $itemData['temperature'] ?? null,
                        'expiration_date' => $itemData['expiration_date'] ?? null,
                        'notes' => $itemData['notes'] ?? null,
                        'is_gift' => $itemData['is_gift'] ?? false,
                        'price_per_unit' => $itemData['price_per_unit'] ?? 0,
                        'reason_for_addition' => $itemData['reason_for_addition'] ?? null,
                    ]);

                    if (!empty($itemData['photo'])) {
                        $path = $itemData['photo']->store('receipt-items', 'public');
                        $receiptItem->photo = $path;
                    }

                    $receipt->items()->save($receiptItem);

                    if ($receiptItem->hasVariance()) {
                        $varianceCount++;
                        $this->createVariance($receipt, $receiptItem, $itemData);
                    }
                }
            }

            $receipt->total_items_received = $receipt->items->count();
            $receipt->total_variance_items = $varianceCount;
            $receipt->save();

            if ($receipt->purchase_order_id) {
                $order = $receipt->purchaseOrder;
                $order->update(['status' => 'delivered']);
            }

            return $receipt->fresh(['items', 'variances']);
        });
    }

    public function saveDraft(array $data, Authenticatable $user): GoodsReceipt
    {
        return DB::transaction(function () use ($data, $user) {
            $data['status'] = 'draft';
            return $this->createReceipt($data, $user);
        });
    }

    public function completeReceipt($receiptId, Authenticatable $user): GoodsReceipt
    {
        return DB::transaction(function () use ($receiptId, $user) {
            $receipt = $this->goodsReceiptRepository->findOrFail($receiptId);

            if ($receipt->status !== 'draft') {
                throw new \Exception('Only draft receipts can be completed');
            }

            $receipt->update(['status' => 'completed']);

            if ($receipt->purchase_order_id) {
                $order = $receipt->purchaseOrder;
                $order->update(['status' => 'delivered', 'completed_at' => now()]);
            }

            return $receipt->fresh();
        });
    }

    public function getReceiptDetails($receiptId)
    {
        return $this->goodsReceiptRepository->findWithRelations($receiptId, [
            'purchaseOrder',
            'branch',
            'receivedBy',
            'supplier',
            'items.item',
            'variances.compensatoryOrder',
        ]);
    }

    public function handleVariance($receiptId, $varianceId, string $action, array $data, Authenticatable $user): GoodsReceipt
    {
        return DB::transaction(function () use ($receiptId, $varianceId, $action, $data, $user) {
            $receipt = $this->goodsReceiptRepository->findOrFail($receiptId);
            $variance = $receipt->variances()->findOrFail($varianceId);

            $variance->update(['action_taken' => $action]);

            switch ($action) {
                case 'accept_variance':
                    $variance->update(['notes' => $data['notes'] ?? 'Variance accepted']);
                    break;

                case 'create_compensatory_order':
                    $variance->update(['notes' => $data['notes'] ?? 'Compensatory order created']);
                    break;

                case 'deduct_from_invoice':
                    $variance->update([
                        'deduction_amount' => $variance->variance_amount,
                        'deduction_reason' => $data['notes'] ?? 'Deducted from invoice',
                    ]);

                    $receipt->total_amount -= $variance->variance_amount;
                    $receipt->save();
                    break;
            }

            if (!empty($data['photo_evidence'])) {
                $path = $data['photo_evidence']->store('variance-evidence', 'public');
                $variance->photo_evidence = $path;
                $variance->save();
            }

            return $receipt->fresh(['variances']);
        });
    }

    private function createVariance(GoodsReceipt $receipt, GoodsReceiptItem $item, array $data): void
    {
        $varianceQuantity = $item->getVarianceQuantity();

        $varianceType = match(true) {
            $varianceQuantity < 0 => 'over',
            $varianceQuantity > 0 => 'short',
            $item->quality === 'poor' => 'damage',
            default => 'short'
        };

        $varianceAmount = abs($varianceQuantity) * ($item->price_per_unit ?? 0);

        GoodsReceiptVariance::create([
            'goods_receipt_id' => $receipt->id,
            'goods_receipt_item_id' => $item->id,
            'variance_type' => $varianceType,
            'variance_quantity' => abs($varianceQuantity),
            'variance_amount' => $varianceAmount,
            'action_taken' => $data['variance_action'] ?? null,
            'notes' => $data['variance_notes'] ?? null,
        ]);
    }
}
