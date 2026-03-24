<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\RecurringOrder\Enums\RecurringOrderStatus;
use Modules\RecurringOrder\Jobs\ProcessRecurringOrdersJob;
use Modules\RecurringOrder\Models\RecurringOrder;
use Modules\Supplier\Models\Supplier;
use Tests\TestCase;

class ProcessRecurringOrdersJobTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Job runs without error when there are no due recurring orders.
     */
    public function test_job_handles_no_due_orders(): void
    {
        ProcessRecurringOrdersJob::dispatchSync();
        $this->assertDatabaseCount('purchase_orders', 0);
    }

    /**
     * When a due recurring order exists, job creates one purchase order and updates recurring order status and next_run_at.
     * Requires branches, branch_managers, (suppliers or branch_managers), items, recurring_orders, recurring_order_items.
     */
    public function test_job_creates_purchase_order_and_updates_recurring_order_when_due(): void
    {
        $branch = \Modules\Branch\Models\Branch::create([
            'name' => 'Test Branch',
            'location' => 'Test',
            'lat' => 0,
            'lng' => 0,
        ]);
        $manager = \Modules\BranchManagers\Models\BranchManager::create([
            'name' => 'Test Manager',
            'email' => 'manager@test.recurring',
            'password' => bcrypt('password'),
            'branch_id' => $branch->id,
            'phone' => '1234567890',
        ]);
        $supplier = Supplier::create([
            'name' => 'Test Supplier',
            'email' => 'supplier@test.recurring',
            'password' => bcrypt('password'),
            'phone' => '1234567891',
            'is_active' => true,
            'status' => 'online',
        ]);
        $item = \Modules\Purchase\Models\Item::create([
            'name' => 'Test Item',
            'code' => 'TI001',
            'unit' => 'pcs',
            'is_active' => true,
        ]);

        $recurring = RecurringOrder::create([
            'branch_id' => $branch->id,
            'created_by' => $manager->id,
            'order_name' => 'Test Recurring',
            'order_source_type' => 'direct_supplier',
            'status' => RecurringOrderStatus::PENDING,
            'sourceable_type' => \Modules\Supplier\Models\Supplier::class,
            'sourceable_id' => $supplier->id,
            'repeat_frequency' => 'weekly',
            'repeat_config' => ['repeat_days' => [1]],
            'scheduling_time_am' => '10:00',
            'start_date' => now()->subDay(),
            'end_type' => 'repeat',
            'next_run_at' => now()->subMinute(),
        ]);

        \Modules\RecurringOrder\Models\RecurringOrderItem::create([
            'recurring_order_id' => $recurring->id,
            'item_id' => $item->id,
            'item_name' => $item->name,
            'quantity' => 1,
            'unit_price' => 10,
            'quality' => 'standard',
        ]);

        $beforeCount = PurchaseOrder::count();
        ProcessRecurringOrdersJob::dispatchSync();

        $this->assertDatabaseCount('purchase_orders', $beforeCount + 1);
        $recurring->refresh();
        $this->assertSame(RecurringOrderStatus::GENERATED->value, $recurring->status->value);
        $this->assertNotNull($recurring->next_run_at);
        $po = PurchaseOrder::where('recurring_order_id', $recurring->id)->first();
        $this->assertNotNull($po);
    }
}
