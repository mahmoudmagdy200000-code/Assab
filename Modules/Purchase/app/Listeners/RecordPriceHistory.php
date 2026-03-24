<?php

namespace Modules\Purchase\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Purchase\Events\GoodsReceived;
use Modules\Purchase\Models\PriceHistory;

class RecordPriceHistory implements ShouldQueue
{
    use InteractsWithQueue;
    public $afterCommit = true;
    public $tries = 3;

    public function backoff(): array
    {
        $jitter = random_int(1, 3);
        return [8 + $jitter, 25 + $jitter, 75 + $jitter];
    }

    public function handle(GoodsReceived $event): void
    {
        $receipt = $event->receipt;
        $order = $receipt->purchaseOrder;
        
        // Record price for each item
        foreach ($receipt->items as $item) {
            if (!$item->is_unlisted && $item->item_id) {
                PriceHistory::recordPrice(
                    $item->item_id,
                    $item->item_name,
                    $order->order_type,
                    $order->supplier_id ?? $order->from_branch_id,
                    $order->supplier?->name ?? $order->fromBranch?->name,
                    $item->unit_price,
                    $item->quality_ordered,
                    $item->unit_of_measurement,
                    $order->order_type->isTransfer() ? null : ceil($order->supplier?->default_delivery_hours / 24),
                    $order->supplier?->rating
                );
            }
        }
    }
}

