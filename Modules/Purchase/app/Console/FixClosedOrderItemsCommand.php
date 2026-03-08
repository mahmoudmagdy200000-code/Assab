<?php

namespace Modules\Purchase\Console;

use Illuminate\Console\Command;
use Modules\Purchase\Enums\OrderItemStatus;
use Modules\Purchase\Enums\OrderStatus;
use Modules\Purchase\Models\PurchaseOrder;

class FixClosedOrderItemsCommand extends Command
{
    protected $signature = 'purchase:fix-closed-order-items
                            {--dry-run : Preview affected records without making changes}
                            {--chunk=200 : Number of orders to process per batch}';

    protected $description = 'Set all items of closed purchase orders to closed status';

    public function handle(): int
    {
        $isDryRun = (bool) $this->option('dry-run');
        $chunk    = (int) $this->option('chunk');

        if ($isDryRun) {
            $this->warn('DRY RUN — no changes will be saved.');
        }

        $closedValue  = OrderItemStatus::CLOSED->value;
        $totalOrders  = 0;
        $totalItems   = 0;

        PurchaseOrder::query()
            ->where('status', OrderStatus::CLOSED->value)
            ->whereHas('items', fn ($q) => $q->where('status', '!=', $closedValue))
            ->select(['id', 'order_number'])
            ->chunk($chunk, function ($orders) use ($isDryRun, $closedValue, &$totalOrders, &$totalItems) {
                foreach ($orders as $order) {
                    $affected = $order->items()
                        ->where('status', '!=', $closedValue)
                        ->count();

                    if ($affected === 0) {
                        continue;
                    }

                    $this->line("Order [{$order->order_number}] → {$affected} item(s) to fix");

                    if (!$isDryRun) {
                        $order->items()
                            ->where('status', '!=', $closedValue)
                            ->update(['status' => $closedValue]);
                    }

                    $totalOrders++;
                    $totalItems += $affected;
                }
            });

        $action = $isDryRun ? 'Would fix' : 'Fixed';
        $this->info("{$action} {$totalItems} item(s) across {$totalOrders} closed order(s).");

        return self::SUCCESS;
    }
}
