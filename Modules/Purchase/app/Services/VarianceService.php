<?php

namespace Modules\Purchase\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Modules\Purchase\Enums\VarianceAction;
use Modules\Purchase\Enums\VarianceType;
use Modules\Purchase\Models\CompensatoryOrder;
use Modules\Purchase\Models\GoodsReceipt;
use Modules\Purchase\Models\GoodsReceiptItem;
use Modules\Purchase\Models\PurchaseInvoice;
use Modules\Purchase\Models\PurchaseVariance;

class VarianceService
{
    public function __construct(
        private readonly TimelineService $timelineService
    ) {}

    /**
     * Create variance record
     */
    public function createVariance(GoodsReceipt $receipt, GoodsReceiptItem $item): PurchaseVariance
    {
        return PurchaseVariance::create([
            'goods_receipt_id' => $receipt->id,
            'goods_receipt_item_id' => $item->id,
            'purchase_order_id' => $receipt->purchase_order_id,
            'item_name' => $item->item_name,
            'item_logo' => $item->item_logo,
            'variance_type' => $item->variance_type,
            'quantity_ordered' => $item->quantity_ordered,
            'quantity_received' => $item->quantity_received,
            'quantity_variance' => $item->quantity_variance,
            'quality_ordered' => $item->quality_ordered,
            'quality_received' => $item->quality_received,
            'unit_price' => $item->unit_price,
            'variance_amount' => $item->variance_amount,
            'status' => 'pending',
        ]);
    }

    /**
     * Get pending variances
     */
    public function getPendingVariances(string $branchId, int $perPage = 15): LengthAwarePaginator
    {
        return PurchaseVariance::with(['goodsReceipt', 'purchaseOrder.supplier'])
            ->whereHas('goodsReceipt', fn($q) => $q->where('branch_id', $branchId))
            ->pending()
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    /**
     * Get escalated variances
     */
    public function getEscalatedVariances(int $perPage = 15): LengthAwarePaginator
    {
        return PurchaseVariance::with(['goodsReceipt', 'purchaseOrder.supplier'])
            ->escalated()
            ->orderBy('escalated_at', 'desc')
            ->paginate($perPage);
    }

    /**
     * Accept variance as is
     */
    public function acceptVariance(PurchaseVariance $variance): PurchaseVariance
    {
        $variance->acceptAsIs();
        $this->timelineService->logVarianceAccepted($variance);
        
        return $variance->fresh();
    }

    /**
     * Create compensatory order
     */
    public function createCompensatoryOrder(PurchaseVariance $variance, array $data): CompensatoryOrder
    {
        return DB::transaction(function () use ($variance, $data) {
            $compensatory = $variance->createCompensatoryOrder($data);
            
            $this->timelineService->logCompensatoryOrderCreated($variance, $compensatory);
            
            return $compensatory;
        });
    }

    /**
     * Deduct from invoice
     */
    public function deductFromInvoice(PurchaseVariance $variance, float $amount, string $reason, ?string $notes = null): void
    {
        DB::transaction(function () use ($variance, $amount, $reason, $notes) {
            $variance->deductFromInvoice($amount, $reason, $notes);
            
            // Update the invoice if it exists
            $invoice = $variance->goodsReceipt->invoice;
            if ($invoice) {
                $invoice->applyDeduction(
                    $amount,
                    $reason,
                    [
                        'variance_id' => $variance->id,
                        'item_name' => $variance->item_name,
                        'variance_type' => $variance->variance_type->value,
                    ]
                );
            }
            
            $this->timelineService->logInvoiceDeducted($variance, $amount);
        });
    }

    /**
     * Handle supplier approval
     */
    public function supplierApprove(PurchaseVariance $variance, string $respondedBy, ?string $response = null): void
    {
        $variance->supplierApprove($respondedBy, $response);
        $this->timelineService->logVarianceApproved($variance);
    }

    /**
     * Handle supplier rejection
     */
    public function supplierReject(PurchaseVariance $variance, string $respondedBy, string $reason): void
    {
        $variance->supplierReject($respondedBy, $reason);
        $this->timelineService->logVarianceRejected($variance, $reason);
    }

    /**
     * Accept supplier rejection
     */
    public function acceptRejection(PurchaseVariance $variance): void
    {
        $variance->update(['status' => 'closed']);
        $this->timelineService->logRejectionAccepted($variance);
        
        // Close the order
        $variance->purchaseOrder->close();
    }

    /**
     * Escalate variance
     */
    public function escalateVariance(PurchaseVariance $variance, string $reason, string $escalatedTo): void
    {
        $variance->escalate($reason, $escalatedTo);
        $this->timelineService->logVarianceEscalated($variance, $reason);
    }

    /**
     * Resolve variance (by Brand Owner)
     */
    public function resolveVariance(PurchaseVariance $variance, string $resolvedBy, ?string $notes = null): void
    {
        $variance->resolve($resolvedBy, $notes);
        $this->timelineService->logVarianceResolved($variance);
        
        // Close the order
        $variance->purchaseOrder->close();
    }

    /**
     * Add photo evidence
     */
    public function addPhotoEvidence(PurchaseVariance $variance, array $photos): void
    {
        $existingPhotos = $variance->photo_evidence ?? [];
        $variance->update([
            'photo_evidence' => array_merge($existingPhotos, $photos),
        ]);
    }

    /**
     * Get variance details
     */
    public function getVarianceDetails(string $varianceId): ?PurchaseVariance
    {
        return PurchaseVariance::with([
            'goodsReceipt',
            'goodsReceiptItem',
            'purchaseOrder.supplier',
            'compensatoryOrder',
            'timelines',
        ])->find($varianceId);
    }

    /**
     * Get variance summary for receipt
     */
    public function getVarianceSummary(string $receiptId): array
    {
        $variances = PurchaseVariance::byReceipt($receiptId)->get();
        
        return [
            'total_variances' => $variances->count(),
            'short_quantity' => $variances->where('variance_type', VarianceType::SHORT)->count(),
            'damaged_quality' => $variances->where('variance_type', VarianceType::DAMAGE)->count(),
            'both' => $variances->where('variance_type', VarianceType::BOTH)->count(),
            'total_variance_amount' => $variances->sum('variance_amount'),
            'pending' => $variances->where('status', 'pending')->count(),
            'resolved' => $variances->whereIn('status', ['resolved', 'closed'])->count(),
        ];
    }
}

