<?php

namespace Modules\Purchase\Console;

use Illuminate\Console\Command;
use Modules\Purchase\Enums\OrderItemStatus;
use Modules\Purchase\Enums\OrderStatus;
use Modules\Purchase\Models\PurchaseOrder;

/**
 * Fixes existing closed orders whose items were left in non-closed statuses (e.g. variance).
 * This is a one-time data-repair command to complement the code fix in PurchaseOrder::transitionTo().
 *
 * Run: php artisan purchase:fix-closed-order-items [--dry-run]
 */
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

        $terminalItemStatuses = [
            OrderItemStatus::CLOSED->value,
        ];

        $totalOrders  = 0;
        $totalItems   = 0;

        PurchaseOrder::query()
            ->where('status', OrderStatus::CLOSED->value)
            ->whereHas('items', fn ($q) => $q->whereNotIn('status', $terminalItemStatuses))
            ->select(['id', 'order_number'])
            ->chunk($chunk, function ($orders) use ($isDryRun, $terminalItemStatuses, &$totalOrders, &$totalItems) {
                foreach ($orders as $order) {
                    $affectedCount = $order->items()
                        ->whereNotIn('status', $terminalItemStatuses)
                        ->count();

                    if ($affectedCount === 0) {
                        continue;
                    }

                    $this->line("Order [{$order->order_number}] → {$affectedCount} item(s) to fix");

                    if (!$isDryRun) {
                        $order->items()
                            ->whereNotIn('status', $terminalItemStatuses)
                            ->update(['status' => OrderItemStatus::CLOSED->value]);
                    }

                    $totalOrders++;
                    $totalItems += $affectedCount;
                }
            });

        $action = $isDryRun ? 'Would fix' : 'Fixed';
        $this->info("{$action} {$totalItems} item(s) across {$totalOrders} closed order(s).");

        return self::SUCCESS;
    }
}
