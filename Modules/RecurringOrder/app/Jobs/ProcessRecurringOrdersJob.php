<?php

namespace Modules\RecurringOrder\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Purchase\Enums\OrderStatus as PurchaseOrderStatus;
use Modules\Purchase\Enums\OrderType;
use Modules\Purchase\Enums\QualityLevel;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Services\PurchaseOrderService;
use Modules\RecurringOrder\Enums\OrderSourceType;
use Modules\RecurringOrder\Enums\RecurringOrderStatus;
use Modules\RecurringOrder\Enums\RepeatFrequency;
use Modules\RecurringOrder\Models\RecurringOrder;
use Modules\RecurringOrder\Services\RecurringOrderService;

class ProcessRecurringOrdersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct()
    {
        // Dependencies (PurchaseOrderService, RecurringOrderService) are resolved
        // via Laravel's method injection in handle(); no constructor args required.
    }

    public function handle(PurchaseOrderService $purchaseOrderService, RecurringOrderService $recurringOrderService): void
    {
        // FR-006 / SC-004: Only pending, non-paused orders with next_run_at due are processed; paused or inactive orders are excluded.
        $due = RecurringOrder::with(['items', 'sourceable'])
            ->where('status', RecurringOrderStatus::PENDING)
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', now())
            ->where(function ($q) {
                $q->whereNull('end_date')->orWhere('end_date', '>=', today());
            })
            ->whereNull('paused_at')
            ->where(function ($q) {
                $q->where('repeat_frequency', '!=', RepeatFrequency::BASED_ON_INVENTORY)
                    ->orWhereNotNull('next_run_at');
            })
            ->orderBy('next_run_at')
            ->limit(50)
            ->get();

        foreach ($due as $recurring) {
            try {
                $this->processOne($recurring, $purchaseOrderService, $recurringOrderService);
            } catch (\Throwable $e) {
                Log::error('ProcessRecurringOrdersJob: failed to process recurring order', [
                    'recurring_order_id' => $recurring->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }
    }

    private function processOne(
        RecurringOrder $recurring,
        PurchaseOrderService $purchaseOrderService,
        RecurringOrderService $recurringOrderService
    ): void {
        // Idempotency: if we already created a PO for this recurring order in the last 5 minutes (e.g. retry), skip create and only update next_run_at
        if (PurchaseOrder::where('recurring_order_id', $recurring->id)
            ->where('created_at', '>=', now()->subMinutes(5))
            ->exists()) {
            $nextRun = $recurringOrderService->computeNextRunAtFromModel($recurring->fresh());
            $recurring->update([
                'status' => RecurringOrderStatus::GENERATED,
                'next_run_at' => $nextRun,
            ]);

            return;
        }

        $sourceType = $recurring->order_source_type?->value ?? $recurring->order_source_type;
        $orderType = ($sourceType === OrderSourceType::DIRECT_SUPPLIER->value || $sourceType === 'direct_supplier')
            ? OrderType::DIRECT_SUPPLIER
            : OrderType::VIA_PURCHASING_OFFICER;

        $items = $recurring->items->map(function ($item) {
            return [
                'item_id' => $item->item_id,
                'quantity' => (float) $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'quality' => $item->quality ?? QualityLevel::STANDARD->value,
            ];
        })->all();

        $notificationChannels = $recurring->notification_channels && is_array($recurring->notification_channels) && count($recurring->notification_channels) > 0
            ? array_values($recurring->notification_channels)
            : ['app'];

        $recurringMetadata = null;
        if (! empty($recurring->notification_options) || ! empty($recurring->smart_settings)) {
            $recurringMetadata = array_filter([
                'notification_options' => $recurring->notification_options ?? [],
                'smart_settings' => $recurring->smart_settings ?? [],
            ]);
        }

        $data = [
            'order_type' => $orderType->value,
            'status' => PurchaseOrderStatus::PENDING,
            'branch_id' => $recurring->branch_id,
            'requested_by' => $recurring->created_by,
            'sourceable_type' => $recurring->sourceable_type,
            'sourceable_id' => $recurring->sourceable_id,
            'supplier_id' => $orderType === OrderType::DIRECT_SUPPLIER ? $recurring->sourceable_id : null,
            'quality_level' => $items[0]['quality'] ?? QualityLevel::STANDARD->value,
            'notification_channels' => $notificationChannels,
            'message' => 'Auto-generated from recurring order: '.$recurring->order_name,
            'recurring_order_id' => $recurring->id,
            'recurring_metadata' => $recurringMetadata,
            'items' => $items,
        ];

        $purchaseOrderService->createOrder($data);

        $nextRun = $recurringOrderService->computeNextRunAtFromModel($recurring->fresh());
        $recurring->update([
            'status' => RecurringOrderStatus::GENERATED,
            'next_run_at' => $nextRun,
        ]);
    }
}
