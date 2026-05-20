<?php

namespace Modules\Purchase\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Purchase\Models\OrderDocument;
use Modules\Purchase\Models\OrderTimeline;
use Modules\Purchase\Models\PurchaseOrder;

/**
 * Permanently deletes purchase orders and all related data from the database.
 * Use after running PurchaseOrdersBulkSeeder to clean bulk-seeded orders, or to reset purchase orders.
 *
 * Deletes in correct order: polymorphic timelines/documents first, then orders (DB cascades handle
 * items, goods_receipts, invoices, variances, return_orders, tracking_stages, delivery_proofs, feedbacks).
 *
 * Run: php artisan purchase:clean-bulk-orders [--force] [--chunk=500]
 */
class CleanBulkPurchaseOrdersCommand extends Command
{
    protected $signature = 'purchase:clean-bulk-orders
                            {--force : Skip confirmation}
                            {--chunk=500 : Number of orders to delete per chunk}';

    protected $description = 'Permanently delete all purchase orders and related data from the database';

    private const PURCHASE_ORDER_MORPH_TYPE = 'Modules\Purchase\Models\PurchaseOrder';

    public function handle(): int
    {
        $total = PurchaseOrder::count();

        if ($total === 0) {
            $this->info('No purchase orders found. Nothing to delete.');

            return Command::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("This will permanently delete {$total} purchase order(s) and all related data. Continue?")) {
            $this->info('Aborted.');

            return Command::SUCCESS;
        }

        $chunkSize = (int) $this->option('chunk');
        $chunkSize = max(100, min(2000, $chunkSize));

        $this->info("Deleting {$total} purchase orders in chunks of {$chunkSize}...");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $deleted = 0;

        PurchaseOrder::query()
            ->select('id')
            ->orderBy('id')
            ->chunkById($chunkSize, function ($orders) use (&$deleted, $bar) {
                $ids = $orders->pluck('id')->all();

                DB::transaction(function () use ($ids) {
                    $this->deletePolymorphicForOrders($ids);
                    PurchaseOrder::whereIn('id', $ids)->forceDelete();
                });

                $deleted += count($ids);
                $bar->advance(count($ids));
            });

        $bar->finish();
        $this->newLine();
        $this->info("Deleted {$deleted} purchase order(s) and related data.");

        return Command::SUCCESS;
    }

    /**
     * Delete order_timelines and order_documents for the given purchase order IDs (polymorphic; no FK cascade).
     */
    private function deletePolymorphicForOrders(array $orderIds): void
    {
        OrderTimeline::query()
            ->where('timelineable_type', self::PURCHASE_ORDER_MORPH_TYPE)
            ->whereIn('timelineable_id', $orderIds)
            ->delete();

        OrderDocument::query()
            ->where('documentable_type', self::PURCHASE_ORDER_MORPH_TYPE)
            ->whereIn('documentable_id', $orderIds)
            ->forceDelete();
    }
}
