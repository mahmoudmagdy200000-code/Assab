<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Inventory\Models\InventoryStockMovement;
use Modules\Purchase\Enums\OrderStatus;
use Modules\Purchase\Enums\OrderType;
use Modules\Purchase\Models\BranchInventory;
use Modules\Purchase\Models\BranchItem;
use Modules\Purchase\Models\GoodsReceipt;
use Modules\Purchase\Models\GoodsReceiptItem;
use Modules\Purchase\Models\Item;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Models\PurchaseOrderItem;
use Modules\Purchase\Models\PurchaseVariance;
use Modules\Purchase\Services\PriceComparisonService;
use Modules\Supplier\Models\Supplier;
use Tests\TestCase;

class PurchaseStage1ReceivingAndInventoryTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private Branch $branch;

    private BranchManager $manager;

    private Supplier $supplier;

    private Item $item;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = AsabCompany::create([
            'name' => 'Stage 1 Co',
            'plan' => 'Professional',
            'status' => 'active',
        ]);
        $brand = AsabBrand::create([
            'company_id' => $this->company->id,
            'name' => 'Brand S1',
            'abbr' => 'S1',
            'sub_status' => 'active',
            'status' => 'active',
        ]);
        $this->branch = Branch::factory()->create([
            'asab_company_id' => $this->company->id,
            'asab_brand_id' => $brand->id,
        ]);
        $this->manager = BranchManager::factory()->create(['branch_id' => $this->branch->id]);
        $this->supplier = Supplier::create([
            'name' => 'Stage 1 Supplier',
            'email' => 'supplier-s1@test.local',
            'password' => 'secret123',
            'is_active' => true,
        ]);
        $this->item = Item::create([
            'name' => 'Tomatoes',
            'unit' => 'kg',
            'is_active' => true,
        ]);
    }

    private function createConfirmedOrder(float $qty = 10.0, float $unitPrice = 30.0): array
    {
        $order = PurchaseOrder::create([
            'order_type' => OrderType::DIRECT_SUPPLIER,
            'status' => OrderStatus::CONFIRMED,
            'branch_id' => $this->branch->id,
            'requested_by' => $this->manager->id,
            'sourceable_type' => Supplier::class,
            'sourceable_id' => $this->supplier->id,
            'supplier_id' => $this->supplier->id,
            'subtotal' => round($qty * $unitPrice, 2),
            'tax_rate' => 15.00,
            'tax_amount' => round($qty * $unitPrice * 0.15, 2),
            'total_amount' => round($qty * $unitPrice * 1.15, 2),
        ]);

        $orderItem = PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'item_id' => $this->item->id,
            'item_name' => $this->item->name,
            'quantity_ordered' => $qty,
            'quantity_confirmed' => $qty,
            'unit_price' => $unitPrice,
            'total_price' => round($qty * $unitPrice, 2),
            'unit_of_measurement' => 'kg',
            'status' => 'confirmed',
        ]);

        return [$order, $orderItem];
    }

    public function test_t1_1_invalid_items_do_not_close_order_or_create_receipt(): void
    {
        [$order] = $this->createConfirmedOrder(10, 30);
        [$otherOrder, $foreignItem] = $this->createConfirmedOrder(5, 20);

        // Foreign order item is blocked fail-closed (403) without closing PO
        $resForeign = $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/purchase/receiving/orders/{$order->id}/start", [
                'items' => [[
                    'item_id' => $foreignItem->id,
                    'quantity_received' => 5,
                    'quality' => 'normal',
                ]],
            ]);

        $resForeign->assertStatus(403);
        $this->assertSame(OrderStatus::CONFIRMED, $order->fresh()->status);
        $this->assertSame(0, GoodsReceipt::where('purchase_order_id', $order->id)->count());

        // Non-existent item UUID for this order is rejected (403 replay-auth) without closing PO
        $resInvalid = $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/purchase/receiving/orders/{$order->id}/start", [
                'items' => [[
                    'item_id' => '00000000-0000-0000-0000-000000000000',
                    'quantity_received' => 5,
                    'quality' => 'normal',
                ]],
            ]);

        $resInvalid->assertStatus(403);
        $this->assertSame(OrderStatus::CONFIRMED, $order->fresh()->status);
        $this->assertSame(0, GoodsReceipt::where('purchase_order_id', $order->id)->count());

        // Empty items payload fails validation (422) without closing PO
        $resEmpty = $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/purchase/receiving/orders/{$order->id}/start", [
                'items' => [],
            ]);

        $resEmpty->assertStatus(422);
        $this->assertSame(OrderStatus::CONFIRMED, $order->fresh()->status);
        $this->assertSame(0, GoodsReceipt::where('purchase_order_id', $order->id)->count());
    }

    public function test_t1_1_variance_action_failure_rolls_back_entire_receiving_transaction(): void
    {
        [$order, $orderItem] = $this->createConfirmedOrder(10, 30);

        // Pass an invalid variance.items quantity (0 -> fails validation or service throws)
        // Test service-level exception inside match($action): pass compensatory_order with invalid item qty 0
        $service = app(\Modules\Purchase\Services\GoodsReceiptService::class);

        $threw = false;
        try {
            $service->startReceiving(
                $order,
                $this->manager->id,
                [[
                    'item_id' => $orderItem->id,
                    'quantity_received' => 8,
                    'quality' => 'normal',
                ]],
                'invoice',
                [
                    'invoice_number' => 'INV-RB-01',
                    'invoice_date' => today()->toDateString(),
                    'tax_rate' => 15,
                ],
                [
                    'action' => 'compensatory_order',
                    'items' => [[
                        'item_id' => $orderItem->id,
                        'quantity' => 0,
                    ]],
                ]
            );
        } catch (\Throwable $e) {
            $threw = true;
        }

        $this->assertTrue($threw, 'Exception during variance action must propagate instead of being swallowed.');
        $this->assertSame(OrderStatus::CONFIRMED, $order->fresh()->status);
        $this->assertSame(0, GoodsReceipt::where('purchase_order_id', $order->id)->count());
        $this->assertSame(0, PurchaseVariance::where('purchase_order_id', $order->id)->count());
    }

    public function test_t1_1_multi_batch_partial_receiving_tracks_cumulative_qty_and_closes_on_completion(): void
    {
        [$order, $orderItem] = $this->createConfirmedOrder(10, 30);

        // Batch 1: receive 4 of 10
        $res1 = $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/purchase/receiving/orders/{$order->id}/start", [
                'items' => [[
                    'item_id' => $orderItem->id,
                    'quantity_received' => 4,
                    'quality' => 'normal',
                ]],
                'document_type' => 'delivery_note',
            ]);

        $res1->assertStatus(201);
        $order->refresh();
        $orderItem->refresh();

        $this->assertSame(OrderStatus::DELIVERED, $order->status);
        $this->assertTrue($order->can_receive);
        $this->assertEquals(4.0, (float) $orderItem->quantity_received);

        // Batch 2: receive remaining 6 of 10
        $res2 = $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/purchase/receiving/orders/{$order->id}/start", [
                'items' => [[
                    'item_id' => $orderItem->id,
                    'quantity_received' => 6,
                    'quality' => 'normal',
                ]],
                'document_type' => 'invoice',
                'invoice_data' => [
                    'invoice_number' => 'INV-BATCH-2',
                    'invoice_date' => today()->toDateString(),
                    'tax_rate' => 15,
                ],
            ]);

        $res2->assertStatus(201);
        $order->refresh();
        $orderItem->refresh();

        $this->assertSame(OrderStatus::CLOSED, $order->status);
        $this->assertEquals(10.0, (float) $orderItem->quantity_received);
        $this->assertSame(2, GoodsReceipt::where('purchase_order_id', $order->id)->count());

        // Inventory posted cumulatively across both batches (4 + 6 = 10)
        $inv = BranchInventory::where('branch_id', $this->branch->id)->where('item_id', $this->item->id)->first();
        $this->assertNotNull($inv);
        $this->assertEquals(10.0, (float) $inv->available_quantity);
        $this->assertEquals(
            10.0,
            (float) InventoryStockMovement::where('branch_id', $this->branch->id)
                ->where('item_id', $this->item->id)
                ->sum('qty_delta')
        );
    }

    /**
     * REG-DEC-01 (T1.2 / DEC-05 / CORRECTION-03):
     * Over-delivery records full actual quantity on receipt item, posts ONLY approved ordered qty
     * to BranchInventory / BranchItem / InventoryStockMovement, reserves ONLY excess pending approval,
     * and excludes excess from all available/transfer consumption paths.
     */
    public function test_t1_2_reg_dec_01_excess_stock_is_reserved_and_excluded_from_available_inventory(): void
    {
        [$order, $orderItem] = $this->createConfirmedOrder(10, 30);

        BranchItem::create([
            'branch_id' => $this->branch->id,
            'item_id' => $this->item->id,
            'price' => 30,
            'quantity' => 5,
        ]);
        BranchInventory::create([
            'branch_id' => $this->branch->id,
            'item_id' => $this->item->id,
            'available_quantity' => 5,
            'reserved_quantity' => 0,
        ]);

        // Receive 13 against 10 ordered (excess = 3)
        $res = $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/purchase/receiving/orders/{$order->id}/start", [
                'items' => [[
                    'item_id' => $orderItem->id,
                    'quantity_received' => 13,
                    'quality' => 'normal',
                ]],
                'document_type' => 'delivery_note',
            ]);

        $res->assertStatus(201);
        $res->assertJsonPath('data.items.0.quantity_received', 13);
        $res->assertJsonPath('data.items.0.quantity_available_posted', 10);
        $res->assertJsonPath('data.items.0.quantity_excess_reserved', 3);
        $res->assertJsonPath('data.items.0.excess_status', 'reserved_pending_approval');

        $receiptItem = GoodsReceiptItem::where('purchase_order_item_id', $orderItem->id)->firstOrFail();
        $this->assertEquals(13.0, (float) $receiptItem->quantity_received);
        $this->assertEquals(10.0, (float) $receiptItem->quantity_available_posted);
        $this->assertEquals(3.0, (float) $receiptItem->quantity_excess_reserved);
        $this->assertSame('reserved_pending_approval', $receiptItem->excess_status);

        // Available stock increases ONLY by 10 (5 + 10 = 15), NOT 18
        $inv = BranchInventory::where('branch_id', $this->branch->id)->where('item_id', $this->item->id)->firstOrFail();
        $this->assertEquals(15.0, (float) $inv->available_quantity);
        $this->assertEquals(15.0, (float) $inv->actual_available);

        $branchItem = BranchItem::where('branch_id', $this->branch->id)->where('item_id', $this->item->id)->firstOrFail();
        $this->assertEquals(15.0, (float) $branchItem->quantity);

        // Movement ledger records ONLY +10 (movement key purchase_receipt:<id>)
        $movement = InventoryStockMovement::where('idempotency_key', 'purchase_receipt:'.$receiptItem->id)->first();
        $this->assertNotNull($movement);
        $this->assertEquals(10.0, (float) $movement->qty_delta);
        $this->assertEquals(
            10.0,
            (float) InventoryStockMovement::where('branch_id', $this->branch->id)
                ->where('item_id', $this->item->id)
                ->sum('qty_delta')
        );

        // PO must NOT close while excess is reserved pending approval
        $this->assertNotSame(OrderStatus::CLOSED, $order->fresh()->status);

        // Transfer/price-comparison consumer must never see the 3 unapproved excess units
        $otherBranch = Branch::factory()->create([
            'asab_company_id' => $this->company->id,
            'asab_brand_id' => $this->branch->asab_brand_id,
        ]);
        $branches = app(PriceComparisonService::class)->getBranchesWithStock($this->item->id, 5, $otherBranch->id);
        $row = collect($branches)->firstWhere('branch_id', $this->branch->id);
        if ($row !== null) {
            $this->assertLessThanOrEqual(15.0, (float) $row['available_quantity']);
            $this->assertNotEquals(18.0, (float) $row['available_quantity']);
        }
    }
}
